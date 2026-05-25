<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartnerLoanApplicationRequest;
use App\Models\Loan;
use App\Models\LoanInterestSetting;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartnerLoanApplicationController extends Controller
{
    public function store(PartnerLoanApplicationRequest $request)
    {
        $existingUser = User::where('phone_number', $request->phone_number)
            ->when($request->filled('email'), fn($q) => $q->orWhere('email', $request->email))
            ->first();
        $isNewUser = $existingUser === null;

        $name         = trim($request->organisation_name);
        $organisation = Organisation::firstOrCreate(
            ['name' => $name],
            ['slug' => Str::slug($name) . '-' . Str::random(6), 'status' => 'active']
        );

        $termMonths   = (int) $request->term_months;
        $interestRate = LoanInterestSetting::rateForTenure($termMonths);

        if ($interestRate <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active interest rate is configured for this loan tenure. Please contact the administrator.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $interestRate, $termMonths, $organisation, $existingUser, $isNewUser) {

                // 1. User — create or update
                if ($isNewUser) {
                    $user = User::create([
                        'organisation_id'     => $organisation->id,
                        'fullname'            => $request->fullname,
                        'phone_number'        => $request->phone_number,
                        'email'               => $request->email,
                        'dob'                 => $request->dob,
                        'gender'              => $request->gender,
                        'nin'                 => $request->nin,
                        'bvn'                 => $request->bvn,
                        'residential_address' => $request->residential_address,
                        'state'               => $request->state,
                        'lga'                 => $request->lga,
                        'bank_name'           => $request->bank_name,
                        'bank_account_number' => $request->bank_account_number,
                        'bank_account_name'   => $request->bank_account_name,
                        'bank_code'           => $request->bank_code,
                        'phone_verified_at'   => now(),
                        'bank_connected_at'   => now(),
                        'kyc_status'          => 'verified',
                        'account_level'       => 'tier_3',
                        'status'              => 'active',
                    ]);
                } else {
                    $user = $existingUser;

                    $userUpdates = array_filter([
                        'fullname'            => $request->fullname,
                        'email'               => $request->email,
                        'dob'                 => $request->dob,
                        'gender'              => $request->gender,
                        'nin'                 => $request->nin,
                        'bvn'                 => $request->bvn,
                        'residential_address' => $request->residential_address,
                        'state'               => $request->state,
                        'lga'                 => $request->lga,
                        'bank_name'           => $request->bank_name,
                        'bank_account_number' => $request->bank_account_number,
                        'bank_account_name'   => $request->bank_account_name,
                        'bank_code'           => $request->bank_code,
                    ], fn($v) => $v !== null);

                    if (!empty($userUpdates)) {
                        if (isset($userUpdates['bank_name']) || isset($userUpdates['bank_account_number'])) {
                            $userUpdates['bank_connected_at'] = now();
                        }
                        $user->update($userUpdates);
                    }
                }

                // 2. Address — create for new user; update latest (or create) if fields supplied
                if ($isNewUser) {
                    $address = $user->addresses()->create([
                        'residential_address' => $request->residential_address,
                        'state'               => $request->state,
                        'lga'                 => $request->lga,
                        'verification_status' => 'verified',
                    ]);
                } elseif ($request->filled('residential_address') || $request->filled('state') || $request->filled('lga')) {
                    $addressData = array_filter([
                        'residential_address' => $request->residential_address,
                        'state'               => $request->state,
                        'lga'                 => $request->lga,
                    ], fn($v) => $v !== null);

                    $address = $user->addresses()->latest()->first();
                    if ($address) {
                        $address->update($addressData);
                    } else {
                        $address = $user->addresses()->create(array_merge($addressData, ['verification_status' => 'verified']));
                    }
                } else {
                    $address = $user->addresses()->latest()->first();
                }

                // 3. Guarantors — create for new user; updateOrCreate by type if supplied; else use existing
                $guarantors = [];
                if (!empty($request->guarantors)) {
                    foreach ($request->guarantors as $guarantorData) {
                        $payload = array_filter([
                            'name'         => $guarantorData['name'],
                            'phone_number' => $guarantorData['phone_number'],
                            'relationship' => $guarantorData['relationship'],
                            'id_type'      => $guarantorData['id_type'] ?? null,
                        ], fn($v) => $v !== null);

                        $guarantors[] = $user->guarantors()->updateOrCreate(
                            ['guarantor_type' => $guarantorData['guarantor_type']],
                            $payload
                        );
                    }
                } else {
                    $guarantors = $user->guarantors()->orderBy('guarantor_type')->get()->all();
                }

                // 4. Employment — create for new user; update latest (or create) if supplied; else use existing
                $employment = null;
                if ($request->filled('employment')) {
                    $emp            = $request->employment;
                    $employmentData = [
                        'employment_information' => $emp['employment_information'],
                        'occupation'             => $emp['occupation'],
                        'educational_details'    => $emp['educational_details'] ?? null,
                        'income'                 => $emp['income'],
                        'verification_status'    => 'verified',
                    ];

                    $employment = $user->employments()->latest()->first();
                    if ($employment) {
                        $employment->update($employmentData);
                    } else {
                        $employment = $user->employments()->create($employmentData);
                    }
                } else {
                    $employment = $user->employments()->latest()->first();
                }

                // 5. Loan — always create a new record
                $amount             = (float) $request->amount_requested;
                $totalInterest      = round($amount * ($interestRate / 100) * ($termMonths / 12), 2);
                $totalRepayable     = round($amount + $totalInterest, 2);
                $monthlyInstallment = round($totalRepayable / $termMonths, 2);

                $loan = Loan::create([
                    'user_id'             => $user->id,
                    'amount_requested'    => $amount,
                    'interest_rate'       => $interestRate,
                    'total_interest'      => $totalInterest,
                    'total_repayable'     => $totalRepayable,
                    'monthly_installment' => $monthlyInstallment,
                    'term_months'         => $termMonths,
                    'status'              => 'pending',
                    'application_reason'  => $request->application_reason,
                ]);

                return compact('user', 'address', 'guarantors', 'employment', 'loan');
            });

            $message = $isNewUser
                ? 'Loan application submitted successfully. The user can set their password when they visit the app.'
                : 'Loan application submitted successfully.';

            return response()->json([
                'status'  => 'success',
                'message' => $message,
                'data'    => [
                    'user'         => $result['user']->only([
                        'id', 'fullname', 'phone_number', 'email',
                        'kyc_status', 'account_level', 'status', 'organisation_id',
                    ]),
                    'organisation' => $organisation->only(['id', 'name', 'slug']),
                    'address'      => $result['address'],
                    'guarantors'   => $result['guarantors'],
                    'employment'   => $result['employment'],
                    'loan'         => $result['loan'],
                ],
            ], 201);

        } catch (\Throwable $e) {
            \Log::error('PartnerLoanApplication failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => 'error',
                'message' => 'An error occurred while processing the application. Please try again.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(string $phone)
    {
        $user = User::where('phone_number', $phone)->first();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No user found with this phone number.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'user'       => $user->only([
                    'id', 'fullname', 'phone_number', 'email', 'dob', 'gender',
                    'nin', 'bvn', 'residential_address', 'state', 'lga',
                    'bank_name', 'bank_account_number', 'bank_account_name',
                    'kyc_status', 'account_level', 'status', 'organisation_id',
                ]),
                'address'    => $user->addresses()->latest()->first(),
                'guarantors' => $user->guarantors()->orderBy('guarantor_type')->get(),
                'employment' => $user->employments()->latest()->first(),
                'loans'      => $user->loans()->with('payments')->orderBy('created_at', 'desc')->get(),
            ],
        ]);
    }
}
