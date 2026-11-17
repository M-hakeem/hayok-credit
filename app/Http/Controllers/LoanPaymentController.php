<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Services\PaymentService;
use App\Services\TransactionPinService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;

class LoanPaymentController extends Controller
{
    public function adminIndex()
    {
        $payments = LoanPayment::with(['loan', 'user', 'repaymentSchedule'])
            ->orderBy('created_at', 'desc')
            ->get();

        $payments->each(function (LoanPayment $payment) {
            $schedule = $payment->repaymentSchedule;

            if (! $schedule) {
                return;
            }

            $totalDueCents = (int) round((float) $schedule->total_due * 100);
            $amountPaidCents = (int) round((float) $schedule->amount_paid * 100);
            $balanceDueCents = max(0, $totalDueCents - $amountPaidCents);

            $payment->setAttribute('installment_total_due', number_format($totalDueCents / 100, 2, '.', ''));
            $payment->setAttribute('balance_due', number_format($balanceDueCents / 100, 2, '.', ''));
        });

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
    #[BodyParameter('pin', type: 'string', required: true, description: 'Four-digit transaction PIN')]
    #[BodyParameter('payment_reference', type: 'string', required: false, description: 'Payment reference string (max 255 chars)')]
    #[BodyParameter('payment_method', type: 'string', required: false, description: 'Uses the wallet first, then a saved card for any remaining amount.')]
    public function store(Request $request, $loanId, PaymentService $paymentService, TransactionPinService $pinService)
    {
        $data = $request->validate([
            'payment_reference' => 'nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'payment_method' => 'nullable|in:wallet,card,auto',
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

        $pinService->verify($user, $data['pin']);

        try {
            $payment = $paymentService->processLoanPayment(
                $user,
                $schedule,
                (float) $data['amount_paid'],
                $data['payment_reference'] ?? null,
            );

            return response()->json([
                'status' => 'success',
                'message' => $payment->status === 'pending'
                    ? 'Wallet portion applied. Card payment is awaiting Paystack confirmation.'
                    : 'Installment paid successfully.',
                'data' => $payment,
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
