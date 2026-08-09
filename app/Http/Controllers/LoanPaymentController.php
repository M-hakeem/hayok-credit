<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;

class LoanPaymentController extends Controller
{
    public function adminIndex()
    {
        $payments = LoanPayment::with(['loan', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $payments,
        ]);
    }

    public function adminLoanPayments($loanId)
    {
        $loan = Loan::find($loanId);

        if (! $loan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        $payments = $loan->payments()->orderBy('due_date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $payments,
        ]);
    }

    public function index($loanId)
    {
        $user = auth()->user();

        $loan = Loan::where('user_id', $user->id)->find($loanId);

        if (! $loan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        $payments = $loan->payments()->orderBy('due_date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $payments,
        ]);
    }

    #[BodyParameter('amount_paid', type: 'number', required: true, description: 'Amount being paid for this installment (min 0.01)')]
    #[BodyParameter('payment_reference', type: 'string', required: false, description: 'Payment reference string (max 255 chars)')]
    #[BodyParameter('payment_method', type: 'string', required: false, description: 'Payment method: wallet, bank, or external')]
    public function store(Request $request, $loanId)
    {
        $request->validate([
            'payment_reference' => 'nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:wallet,bank,external',
        ]);

        $user = auth()->user();

        $loan = Loan::where('user_id', $user->id)->find($loanId);

        if (! $loan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found.',
            ], 404);
        }

        if (! in_array($loan->status, ['active'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payments are not allowed for this loan status.',
            ], 422);
        }

        $schedule = RepaymentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial'])
            ->orderBy('installment_number')
            ->first();

        if (! $schedule) {
            return response()->json([
                'status' => 'error',
                'message' => 'No pending repayment schedule was found for this loan.',
            ], 404);
        }

        $amountPaid = (float) $request->amount_paid;
        $newAmountPaid = round($schedule->amount_paid + $amountPaid, 2);
        $remainingDue = max(0, round($schedule->total_due - $newAmountPaid, 2));
        $scheduleStatus = $newAmountPaid >= $schedule->total_due ? 'paid' : 'partial';

        if ($request->payment_method === 'wallet') {
            $wallet = $user->wallet;

            if (! $wallet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Wallet not found. Verify your NIN or BVN and create a wallet first.',
                ], 404);
            }

            try {
                $wallet->debit(
                    (float) $request->amount_paid,
                    'Loan repayment',
                    $request->payment_reference,
                );
            } catch (\RuntimeException $exception) {
                return response()->json([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        $payment = LoanPayment::create([
            'loan_id' => $loan->id,
            'repayment_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'due_date' => $schedule->due_date,
            'amount_due' => $schedule->total_due,
            'amount_paid' => $amountPaid,
            'paid_at' => now(),
            'status' => $scheduleStatus === 'paid' ? 'paid' : 'partial',
            'payment_reference' => $request->payment_reference,
        ]);

        $schedule->update([
            'amount_paid' => $newAmountPaid,
            'balance_due' => $remainingDue,
            'status' => $scheduleStatus,
        ]);

        if ($loan->repaymentSchedules()->whereIn('status', ['pending', 'partial'])->count() === 0) {
            $loan->update(['status' => 'completed']);
        } else {
            $loan->update(['status' => 'active']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Installment paid successfully.',
            'data' => $payment,
        ]);
    }
}
