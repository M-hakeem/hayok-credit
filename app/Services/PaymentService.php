<?php

namespace App\Services;

use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Paystack\RepaymentService;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentService
{
    public function __construct(private readonly RepaymentService $repaymentService)
    {
    }

    /**
     * Process loan payment with smart routing: wallet first, then card
     *
     * @param  User  $user
     * @param  RepaymentSchedule  $schedule
     * @param  float  $amountPaid
     * @param  string|null  $paymentReference
     * @return LoanPayment
     * @throws \RuntimeException
     */
    public function processLoanPayment(User $user, RepaymentSchedule $schedule, float $amountPaid, ?string $paymentReference = null): LoanPayment
    {
        $loan = $schedule->loan;

        return DB::transaction(function () use ($user, $schedule, $loan, $amountPaid, $paymentReference) {
            // Validate amount
            if ($amountPaid <= 0) {
                throw new \RuntimeException('Payment amount must be greater than zero.');
            }

            if ($amountPaid > (float) $schedule->balance_due) {
                throw new \RuntimeException('Payment amount cannot exceed the installment balance.');
            }

            $paymentMethod = null;
            $paymentAuthorizationId = null;
            $gatewayResponse = null;

            // Try wallet payment first
            $wallet = $user->wallet;
            if ($wallet && (float) $wallet->balance >= $amountPaid) {
                try {
                    $wallet->debit(
                        $amountPaid,
                        'Loan repayment',
                        $paymentReference,
                    );
                    $paymentMethod = 'wallet';
                } catch (Throwable $exception) {
                    // If wallet debit fails, try card
                    $paymentMethod = null;
                }
            }

            // Fallback to card payment if wallet insufficient
            if (!$paymentMethod) {
                $authorization = $user->paymentAuthorizations()
                    ->where('status', 'active')
                    ->where('reusable', true)
                    ->first();

                if (!$authorization || !$authorization->isUsable()) {
                    throw new \RuntimeException('Insufficient wallet balance. Please fund your wallet or authorize a card for automatic payment.');
                }

                $reference = 'loan-repay-'.$loan->id.'-'.$schedule->id.'-'.str()->uuid();
                try {
                    $response = $this->repaymentService->chargeAuthorization(
                        $user,
                        $authorization,
                        (int) $this->repaymentService->amountToMinor($amountPaid),
                        $reference,
                        [
                            'purpose' => 'manual_loan_repayment',
                            'user_id' => $user->id,
                            'loan_id' => $loan->id,
                            'loan_installment_id' => $schedule->id,
                        ]
                    );

                    $gatewayResponse = $response;
                    $paymentMethod = 'card';
                    $paymentAuthorizationId = $authorization->id;
                } catch (Throwable $exception) {
                    throw new \RuntimeException('Card payment failed: '.$exception->getMessage());
                }
            }

            // Calculate new schedule state
            $newAmountPaid = round($schedule->amount_paid + $amountPaid, 2);
            $remainingDue = max(0, round($schedule->total_due - $newAmountPaid, 2));
            $scheduleStatus = $newAmountPaid >= $schedule->total_due ? 'paid' : 'partial';

            // Create payment record
            $payment = LoanPayment::create([
                'loan_id' => $loan->id,
                'repayment_schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'due_date' => $schedule->due_date,
                'amount_due' => $schedule->total_due,
                'amount_paid' => $amountPaid,
                'paid_at' => now(),
                'status' => $scheduleStatus === 'paid' ? 'paid' : 'partial',
                'payment_reference' => $paymentReference,
                'payment_authorization_id' => $paymentAuthorizationId,
                'provider' => $paymentMethod === 'card' ? 'paystack' : null,
                'provider_reference' => $paymentMethod === 'card' ? 'loan-repay-'.$loan->id.'-'.$schedule->id.'-'.str()->uuid() : null,
                'gateway_response' => $gatewayResponse,
                'metadata' => [
                    'payment_method' => $paymentMethod,
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                ],
            ]);

            // Update schedule
            $schedule->update([
                'amount_paid' => $newAmountPaid,
                'balance_due' => $remainingDue,
                'status' => $scheduleStatus,
            ]);

            // Update loan status if all schedules paid
            if ($loan->repaymentSchedules()->whereIn('status', ['pending', 'partial'])->count() === 0) {
                $loan->update(['status' => 'completed']);
            } else {
                $loan->update(['status' => 'active']);
            }

            return $payment;
        });
    }
}
