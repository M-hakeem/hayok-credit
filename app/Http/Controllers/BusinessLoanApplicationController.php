<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBusinessLoanApplicationRequest;
use App\Models\BusinessLoanApplication;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        ]);

        $applications = BusinessLoanApplication::query()
            ->withCount(['documents', 'stakeholders'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $applications->through(fn (BusinessLoanApplication $application) => [
            'reference' => $application->reference,
            'status' => $application->status,
            'risk_rating' => $application->risk_rating,
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

    public function show(BusinessLoanApplication $businessLoanApplication)
    {
        $businessLoanApplication->load([
            'stakeholders.documents',
            'documents' => fn ($query) => $query->whereNull('business_stakeholder_id'),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'reference' => $businessLoanApplication->reference,
                'status' => $businessLoanApplication->status,
                'risk_rating' => $businessLoanApplication->risk_rating,
                'review_notes' => $businessLoanApplication->review_notes,
                'reviewed_at' => $businessLoanApplication->reviewed_at,
                'submitted_at' => $businessLoanApplication->created_at,
                'consent_at' => $businessLoanApplication->consent_at,
                'business' => $businessLoanApplication->business_profile,
                'account_owner' => $businessLoanApplication->account_owner,
                'loan' => $businessLoanApplication->loan_details,
                'documents' => $businessLoanApplication->documents,
                'stakeholders' => $businessLoanApplication->stakeholders,
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
        $path = $file->store("business-kyb/{$application->reference}", 'public');

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
