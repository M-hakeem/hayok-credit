<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Services\PaymentService;
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
    #[BodyParameter('payment_method', type: 'string', required: false, description: 'Payment method: wallet or card (defaults to auto-select: wallet first, then card)')]
    public function store(Request $request, $loanId, PaymentService $paymentService)
    {
        $request->validate([
            'payment_reference' => 'nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
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

        try {
            $payment = $paymentService->processLoanPayment(
                $user,
                $schedule,
                (float) $request->amount_paid,
                $request->payment_reference,
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Installment paid successfully.',
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
