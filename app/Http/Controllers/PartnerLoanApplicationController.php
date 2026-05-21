<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanInterestSetting;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PartnerLoanApplicationController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Organisation
            'organisation_name'    => 'required|string|max:255',

            // User identity
            'fullname'             => 'required|string|max:255',
            'phone_number'         => 'required|string|unique:users,phone_number',
            'email'                => 'nullable|email|unique:users,email',
            'dob'                  => 'required|date',
            'gender'               => 'required|in:male,female',
            'nin'                  => 'required|string|max:11',
            'bvn'                  => 'required|string|max:11',

            // Address
            'residential_address'  => 'required|string|min:10',
            'state'                => 'required|string|max:100',
            'lga'                  => 'required|string|max:100',

            // Bank
            'bank_name'            => 'required|string|max:255',
            'bank_account_number'  => 'required|string|max:64',
            'bank_account_name'    => 'required|string|max:255',
            'bank_code'            => 'nullable|string|max:32',

            // Loan
            'amount_requested'     => 'required|numeric|min:100|max:9999999.99',
            'term_months'          => 'required|integer|min:1|max:60',
            'application_reason'   => 'nullable|string|max:1000',

            // Guarantors — at least one required
            'guarantors'                       => 'required|array|min:1|max:3',
            'guarantors.*.guarantor_type'      => 'required|in:1st,2nd,3rd',
            'guarantors.*.name'                => 'required|string|max:255',
            'guarantors.*.phone_number'        => 'required|string|max:20',
            'guarantors.*.relationship'        => 'required|string|max:100',

            // Employment — optional
            'employment'                           => 'nullable|array',
            'employment.employment_information'    => 'required_with:employment|string|min:5',
            'employment.occupation'                => 'required_with:employment|string|min:3',
            'employment.educational_details'       => 'nullable|string|min:5',
            'employment.income'                    => 'required_with:employment|numeric|min:0',
        ], [
            'phone_number.unique'      => 'This phone number already has an account.',
            'guarantors.min'           => 'At least one guarantor is required.',
            'guarantors.*.guarantor_type.in' => 'Guarantor type must be 1st, 2nd, or 3rd.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

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
            $result = DB::transaction(function () use ($request, $interestRate, $termMonths, $organisation) {
                // 1. Create user — pre-verified by partner
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

                // 2. Address record
                $address = $user->addresses()->create([
                    'residential_address' => $request->residential_address,
                    'state'               => $request->state,
                    'lga'                 => $request->lga,
                    'verification_status' => 'verified',
                ]);

                // 3. Guarantors
                $guarantors = [];
                foreach ($request->guarantors as $guarantorData) {
                    $guarantors[] = $user->guarantors()->create([
                        'guarantor_type' => $guarantorData['guarantor_type'],
                        'name'           => $guarantorData['name'],
                        'phone_number'   => $guarantorData['phone_number'],
                        'relationship'   => $guarantorData['relationship'],
                    ]);
                }

                // 4. Employment (optional)
                $employment = null;
                if ($request->filled('employment')) {
                    $emp        = $request->employment;
                    $employment = $user->employments()->create([
                        'employment_information' => $emp['employment_information'],
                        'occupation'             => $emp['occupation'],
                        'educational_details'    => $emp['educational_details'] ?? null,
                        'income'                 => $emp['income'],
                        'verification_status'    => 'verified',
                    ]);
                }

                // 5. Loan
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

            return response()->json([
                'status'  => 'success',
                'message' => 'Loan application submitted successfully. The user can set their password when they visit the app.',
                'data'    => [
                    'user'         => $result['user']->only([
                        'id', 'fullname', 'phone_number', 'email',
                        'kyc_status', 'account_level', 'status', 'organisation_id',
                    ]),
                    'organisation' => $organisation->only(['id', 'name', 'slug']),
                    'address'    => $result['address'],
                    'guarantors' => $result['guarantors'],
                    'employment' => $result['employment'],
                    'loan'       => $result['loan'],
                ],
            ], 201);

        } catch (\Throwable $e) {
            \Log::error('PartnerLoanApplication failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => 'error',
                'message' => 'An error occurred while processing the application. Please try again.',
            ], 500);
        }
    }
}
