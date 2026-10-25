<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBusinessLoanApplicationRequest;
use App\Models\BusinessLoanApplication;
use App\Models\BusinessLoanDocument;
use App\Models\Loan;
use App\Models\LoanInterestSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @tags Business Loan Applications
 */
class BusinessLoanApplicationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:pending_review,approved,rejected,expired'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $applications = BusinessLoanApplication::query()
            ->withCount(['documents', 'stakeholders'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.trim((string) $request->input('search')).'%';
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', $search)
                        ->orWhere('business_profile->registered_name', 'like', $search)
                        ->orWhere('business_profile->registration_number', 'like', $search);
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $applications->through(fn (BusinessLoanApplication $application) => [
            'id' => $application->id,
            'reference' => $application->reference,
            'status' => $application->status,
            'risk_rating' => $application->risk_rating,
            'review_notes' => $application->review_notes,
            'reviewed_at' => $application->reviewed_at,
            'registered_business_name' => $application->business_profile['registered_name'] ?? null,
            'registration_number' => $application->business_profile['registration_number'] ?? null,
            'amount_requested' => $application->loan_details['amount_requested'] ?? null,
            'term_months' => $application->loan_details['term_months'] ?? null,
            'documents_count' => $application->documents_count,
            'stakeholders_count' => $application->stakeholders_count,
            'submitted_at' => $application->created_at,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $applications,
        ]);
    }

    public function approve(Request $request, BusinessLoanApplication $businessLoanApplication)
    {
        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'risk_rating' => ['nullable', 'string', 'max:20'],
            'payout_account' => ['sometimes', 'array'],
            'payout_account.bank_name' => ['required_with:payout_account', 'string', 'max:255'],
            'payout_account.bank_code' => ['required_with:payout_account', 'string', 'max:32'],
            'payout_account.bank_account_name' => ['required_with:payout_account', 'string', 'max:255'],
            'payout_account.bank_account_number' => ['required_with:payout_account', 'digits:10'],
        ]);

        return $this->review($businessLoanApplication, 'approved', $data);
    }

    public function reject(Request $request, BusinessLoanApplication $businessLoanApplication)
    {
        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
            'risk_rating' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->review($businessLoanApplication, 'rejected', $data);
    }

    public function show(BusinessLoanApplication $businessLoanApplication)
    {
        $businessLoanApplication->load([
            'stakeholders.documents',
            'documents' => fn ($query) => $query->whereNull('business_stakeholder_id'),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $businessLoanApplication->id,
                'reference' => $businessLoanApplication->reference,
                'status' => $businessLoanApplication->status,
                'risk_rating' => $businessLoanApplication->risk_rating,
                'review_notes' => $businessLoanApplication->review_notes,
                'reviewed_at' => $businessLoanApplication->reviewed_at,
                'business_user_id' => $businessLoanApplication->business_user_id,
                'loan_id' => $businessLoanApplication->loan_id,
                'submitted_at' => $businessLoanApplication->created_at,
                'consent_at' => $businessLoanApplication->consent_at,
                'business' => $businessLoanApplication->business_profile,
                'payout_account' => $businessLoanApplication->payout_account,
                'account_owner' => $businessLoanApplication->account_owner,
                'loan' => $businessLoanApplication->loan_details,
                'documents' => $businessLoanApplication->documents,
                'stakeholders' => $businessLoanApplication->stakeholders,
            ],
        ]);
    }

    public function document(
        BusinessLoanApplication $businessLoanApplication,
        BusinessLoanDocument $document,
    ) {
        abort_unless(
            $document->business_loan_application_id === $businessLoanApplication->id,
            404,
        );

        $headers = [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $privateDisk = Storage::disk('local');
        if ($privateDisk->exists($document->file_path)) {
            return $privateDisk->response(
                $document->file_path,
                $document->original_filename,
                $headers,
            );
        }

        $legacyPublicDisk = Storage::disk('public');
        abort_unless($legacyPublicDisk->exists($document->file_path), 404);

        return $legacyPublicDisk->response(
            $document->file_path,
            $document->original_filename,
            $headers,
        );
    }

    private function review(BusinessLoanApplication $businessLoanApplication, string $status, array $data)
    {
        $result = DB::transaction(function () use ($businessLoanApplication, $status, $data) {
            $application = BusinessLoanApplication::query()
                ->lockForUpdate()
                ->findOrFail($businessLoanApplication->getKey());

            if ($application->status !== 'pending_review') {
                return ['error' => 'Only applications pending review can be approved or rejected.'];
            }

            if ($status === 'approved') {
                $business = $application->business_profile;
                $payoutAccount = $data['payout_account'] ?? $application->payout_account;
                $loanDetails = $application->loan_details;

                if (! $payoutAccount) {
                    return ['error' => 'Business payout account details are missing. Update the application before approving it.'];
                }

                $interestRate = LoanInterestSetting::rateForTenure((int) $loanDetails['term_months']);
                if ($interestRate <= 0) {
                    return ['error' => 'No active loan interest rate is configured for this term.'];
                }

                if (User::withTrashed()->where('email', $business['email'])->exists()) {
                    return ['error' => 'A user already exists with this business email. Resolve the duplicate before approving.'];
                }

                if (User::withTrashed()->where('phone_number', $business['phone'])->exists()) {
                    return ['error' => 'A user already exists with this business phone number. Resolve the duplicate before approving.'];
                }

                $businessUser = User::create([
                    'fullname' => $business['registered_name'],
                    'email' => $business['email'],
                    'phone_number' => $business['phone'],
                    'password' => null,
                    'role' => 'user',
                    'bank_name' => $payoutAccount['bank_name'],
                    'bank_account_number' => $payoutAccount['bank_account_number'],
                    'bank_account_name' => $payoutAccount['bank_account_name'],
                    'bank_code' => $payoutAccount['bank_code'],
                    'bank_connected_at' => now(),
                ]);

                $businessUser->wallet()->create([
                    'balance' => 0,
                    'currency' => 'NGN',
                ]);

                $amount = (float) $loanDetails['amount_requested'];
                $term = (int) $loanDetails['term_months'];
                $totalInterest = round($amount * ($interestRate / 100), 2);
                $loan = Loan::create([
                    'user_id' => $businessUser->id,
                    'amount_requested' => $amount,
                    'interest_rate' => $interestRate,
                    'total_interest' => $totalInterest,
                    'total_repayable' => round($amount + $totalInterest, 2),
                    'monthly_installment' => round(($amount + $totalInterest) / $term, 2),
                    'term_months' => $term,
                    'status' => 'pending',
                    'application_reason' => $loanDetails['purpose'],
                ]);

                $application->business_user_id = $businessUser->id;
                $application->loan_id = $loan->id;
                $application->payout_account = $payoutAccount;
            }

            $application->update([
                'status' => $status,
                'risk_rating' => $data['risk_rating'] ?? null,
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            return ['application' => $application->fresh()];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], 422);
        }

        $application = $result['application'];

        return response()->json([
            'status' => 'success',
            'message' => $status === 'approved'
                ? 'Business loan application approved.'
                : 'Business loan application rejected.',
            'data' => [
                'reference' => $application->reference,
                'status' => $application->status,
                'risk_rating' => $application->risk_rating,
                'review_notes' => $application->review_notes,
                'reviewed_at' => $application->reviewed_at,
                'business_user_id' => $application->business_user_id,
                'loan_id' => $application->loan_id,
            ],
        ]);
    }

    /**
     * @requestMediaType multipart/form-data
     */
    public function store(StoreBusinessLoanApplicationRequest $request)
    {
        $data = $request->validated();

        $application = DB::transaction(function () use ($request, $data) {
            $application = BusinessLoanApplication::create([
                'reference' => 'BLA-'.Str::upper(Str::random(12)),
                'business_profile' => array_merge($data['business'], [
                    'nature_of_business' => $data['nature_of_business'],
                    'product_use_case' => $data['product_use_case'],
                    'expected_monthly_transaction_volume' => $data['expected_monthly_transaction_volume'],
                    'expected_monthly_transaction_value' => $data['expected_monthly_transaction_value'],
                    'source_of_funds' => $data['source_of_funds'],
                ]),
                'payout_account' => $data['payout_account'],
                'account_owner' => $data['account_owner'],
                'loan_details' => $data['loan'],
                'status' => 'pending_review',
                'consent_at' => now(),
            ]);

            foreach ($data['stakeholders'] as $index => $stakeholderData) {
                $stakeholder = $application->stakeholders()->create([
                    'full_name' => $stakeholderData['full_name'],
                    'date_of_birth' => $stakeholderData['date_of_birth'],
                    'gender' => $stakeholderData['gender'],
                    'nationality' => $stakeholderData['nationality'],
                    'identification_type' => $stakeholderData['identification_type'],
                    'identification_number' => $stakeholderData['identification_number'],
                    'identification_expires_at' => $stakeholderData['identification_expires_at'] ?? null,
                    'residential_address' => $stakeholderData['residential_address'],
                    'shareholding_percentage' => $stakeholderData['shareholding_percentage'],
                    'role' => $stakeholderData['role'],
                    'is_pep' => $stakeholderData['is_pep'],
                ]);

                $this->storeDocument($request, $application, "stakeholders.{$index}.identity_document", 'stakeholder_identity_document', $stakeholder->id);
                $this->storeDocument($request, $application, "stakeholders.{$index}.proof_of_address", 'stakeholder_proof_of_address', $stakeholder->id);
            }

            foreach ($data['documents'] as $index => $documentData) {
                $this->storeDocument(
                    $request,
                    $application,
                    "documents.{$index}.file",
                    $documentData['type'],
                    null,
                    $documentData['expires_at'] ?? null,
                );
            }

            return $application->load('documents', 'stakeholders');
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Business loan application submitted for compliance review.',
            'data' => [
                'reference' => $application->reference,
                'status' => $application->status,
                'document_count' => $application->documents->count(),
                'stakeholder_count' => $application->stakeholders->count(),
            ],
        ], 201);
    }

    private function storeDocument(StoreBusinessLoanApplicationRequest $request, BusinessLoanApplication $application, string $inputKey, string $type, ?int $stakeholderId = null, ?string $expiresAt = null): void
    {
        /** @var UploadedFile $file */
        $file = $request->file($inputKey);
        $path = $file->store("business-kyb/{$application->reference}", 'local');

        $application->documents()->create([
            'business_stakeholder_id' => $stakeholderId,
            'document_type' => $type,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'expires_at' => $expiresAt,
        ]);
    }
}
