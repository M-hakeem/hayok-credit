<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\RepaymentSchedule;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LoanDisbursementController extends Controller
{
    public function index()
    {
        $disbursements = LoanDisbursement::with(['loan.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $disbursements,
        ]);
    }

    public function disburse(Request $request, $id)
    {
        $request->validate([
            'transaction_reference' => 'nullable|string|max:255',
        ]);

        $disbursement = LoanDisbursement::with('loan')
            ->findOrFail($id);

        if ($disbursement->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'This disbursement has already been processed.',
            ], 422);
        }

        $loan = $disbursement->loan;

        if ($loan->status !== 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan must be approved before it can be disbursed.',
            ], 422);
        }

        $disbursement->update([
            'status' => 'disbursed',
            'transaction_reference' => $request->transaction_reference,
            'disbursed_at' => now(),
        ]);

        $disbursement->refresh();

        // Generate repayment schedule starting from disbursement date if not already created
        if ($loan->repaymentSchedules()->count() === 0) {
            $term = (int) $loan->term_months;
            $principalPerInstallment = round($loan->amount_requested / $term, 2);
            $interestPerInstallment = round($loan->total_interest / $term, 2);

            for ($installment = 1; $installment <= $term; $installment++) {
                $principal = $installment === $term
                    ? round($loan->amount_requested - $principalPerInstallment * ($term - 1), 2)
                    : $principalPerInstallment;
                $interest = $installment === $term
                    ? round($loan->total_interest - $interestPerInstallment * ($term - 1), 2)
                    : $interestPerInstallment;
                $totalDue = round($principal + $interest, 2);

                RepaymentSchedule::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $installment,
                    'due_date' => Carbon::parse($disbursement->disbursed_at)->addMonths($installment)->toDateString(),
                    'principal_amount' => $principal,
                    'interest_amount' => $interest,
                    'penalty_amount' => 0,
                    'total_due' => $totalDue,
                    'amount_paid' => 0,
                    'balance_due' => $totalDue,
                    'status' => 'pending',
                ]);
            }
        }

        $loan->update(['status' => 'active']);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan disbursement marked as disbursed.',
            'data' => $disbursement,
        ]);
    }
}
