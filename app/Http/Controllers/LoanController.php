<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRequest;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInterestSetting;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $loans = Loan::with('payments')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $loans,
        ]);
    }

    public function store(StoreLoanRequest $request)
    {
        $user = auth()->user();

        if ($user->is_blacklisted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account is not eligible for a loan. Please contact support.',
            ], 403);
        }

        $hasActiveLoan = $user->loans()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();

        if ($hasActiveLoan) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have an active loan. Please complete your current loan before applying for a new one.',
            ], 422);
        }

        $termMonths = $request->term_months;
        $interestRate = LoanInterestSetting::rateForTenure((int) $termMonths);

        if ($interestRate <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active loan interest rate is configured. Please contact the administrator.',
            ], 422);
        }

        if (! $user->bank_name || ! $user->bank_account_number || ! $user->bank_account_name) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please connect your bank account before applying for a loan.',
            ], 422);
        }

        $amountRequested = $request->amount_requested;

        $totalInterest = round($amountRequested * ($interestRate / 100) * ($termMonths / 12), 2);
        $totalRepayable = round($amountRequested + $totalInterest, 2);
        $monthlyInstallment = round($totalRepayable / $termMonths, 2);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => $amountRequested,
            'interest_rate' => $interestRate,
            'total_interest' => $totalInterest,
            'total_repayable' => $totalRepayable,
            'monthly_installment' => $monthlyInstallment,
            'term_months' => $termMonths,
            'status' => 'pending',
            'application_reason' => $request->application_reason,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan application submitted successfully. Awaiting review.',
            'data' => $loan,
        ], 201);
    }

   public function show($id)
    {
        $user = auth()->user();

        $loan = Loan::with([
            'payments',
            'disbursement',
            'repaymentSchedules'
        ])->find($id);

        if (!$loan || $loan->user_id != $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $loan
        ]);
    }

    public function adminIndex()
    {
        $loans = Loan::with(['user', 'disbursement'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $loans,
        ]);
    }

    public function adminShow($id)
    {
        $loan = Loan::with(['user', 'payments', 'disbursement', 'repaymentSchedules'])->find($id);

        if (! $loan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $loan,
        ]);
    }

    #[BodyParameter('review_notes', type: 'string', required: false, description: 'Admin review notes (max 1000 chars)')]
    #[BodyParameter('risk_grade', type: 'string', required: false, description: 'Risk grade assigned to the loan (e.g. A, B+, max 10 chars)')]
    public function approve(Request $request, $id)
    {
        $request->validate([
            'review_notes' => 'nullable|string|max:1000',
            'risk_grade' => 'nullable|string|max:10',
        ]);

        $loan = Loan::find($id);

        if (! $loan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        if ($loan->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only pending loans can be approved.',
            ], 422);
        }

        $admin = auth()->user();

        DB::transaction(function () use ($loan, $request, $admin) {
            $loan->update([
                'status'       => 'approved',
                'approved_by'  => $admin->id,
                'approved_at'  => now(),
                'review_notes' => $request->review_notes,
                'risk_grade'   => $request->risk_grade,
            ]);

            LoanDisbursement::create([
                'loan_id'            => $loan->id,
                'user_id'            => $loan->user_id,
                'amount'             => $loan->amount_requested,
                'bank_name'          => $loan->user->bank_name ?? '',
                'bank_account_number'=> $loan->user->bank_account_number ?? '',
                'bank_account_name'  => $loan->user->bank_account_name ?? '',
                'bank_code'          => $loan->user->bank_code,
                'status'             => 'pending',
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Loan approved successfully.',
            'data' => $loan->load('disbursement'),
        ]);
    }

    #[BodyParameter('review_notes', type: 'string', required: true, description: 'Reason for rejection (max 1000 chars)')]
    #[BodyParameter('risk_grade', type: 'string', required: false, description: 'Risk grade assigned to the loan (e.g. A, B+, max 10 chars)')]
    public function reject(Request $request, $id)
    {
        $request->validate([
            'review_notes' => 'required|string|max:1000',
            'risk_grade' => 'nullable|string|max:10',
        ]);

        $loan = Loan::find($id);

        if (! $loan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        if ($loan->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only pending loans can be rejected.',
            ], 422);
        }

        $admin = auth()->user();

        $loan->update([
            'status' => 'rejected',
            'rejected_by' => $admin->id,
            'rejected_at' => now(),
            'review_notes' => $request->review_notes,
            'risk_grade' => $request->risk_grade,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan rejected.',
            'data' => $loan,
        ]);
    }
}
