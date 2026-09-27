<?php

namespace App\Services;

use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Services\Paystack\RepaymentService;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentService
{
    public function __construct(private readonly RepaymentService $repaymentService)
    {
    }

    /**
     * Process a loan payment from the user's wallet.
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

            $wallet = $user->wallet;
            $walletPortion = min($amountPaid, (float) ($wallet?->balance ?? 0));
            $cardPortion = round($amountPaid - $walletPortion, 2);
            $paymentMethod = $walletPortion > 0 && $cardPortion > 0 ? 'wallet_and_card' : ($cardPortion > 0 ? 'card' : 'wallet');
            $authorization = null;
            $gatewayResponse = null;
            $providerReference = null;

            if ($walletPortion > 0) {
                $wallet->debit($walletPortion, 'Loan repayment', $paymentReference);
            }

            if ($cardPortion > 0) {
                $authorization = $user->paymentAuthorizations()
                    ->where('status', 'active')->where('reusable', true)->first();
                if (! $authorization || ! $authorization->isUsable()) {
                    throw new \RuntimeException('Insufficient wallet balance. Please fund your wallet or authorize a card for the remaining amount.');
                }

                $providerReference = 'loan-repay-'.$loan->id.'-'.$schedule->id.'-'.str()->uuid();
                try {
                    $gatewayResponse = $this->repaymentService->chargeAuthorization(
                        $user,
                        $authorization,
                        $this->repaymentService->amountToMinor($cardPortion),
                        $providerReference,
                        ['purpose' => 'loan_repayment', 'user_id' => $user->id, 'loan_id' => $loan->id, 'loan_installment_id' => $schedule->id]
                    );
                } catch (Throwable $exception) {
                    throw new \RuntimeException('Card payment failed: '.$exception->getMessage());
                }
            }

            // Wallet funds are applied immediately; card funds are finalized by webhook.
            $appliedAmount = $cardPortion > 0 ? $walletPortion : $amountPaid;
            $newAmountPaid = round($schedule->amount_paid + $appliedAmount, 2);
            $remainingDue = max(0, round($schedule->total_due - $newAmountPaid, 2));
            $scheduleStatus = $newAmountPaid >= $schedule->total_due ? 'paid' : 'partial';

            // Create payment record
            $payment = LoanPayment::create([
                'loan_id' => $loan->id,
                'repayment_schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'due_date' => $schedule->due_date,
                'amount_due' => $cardPortion > 0 ? $cardPortion : $amountPaid,
                'amount_paid' => $walletPortion,
                'paid_at' => $cardPortion > 0 ? null : now(),
                'status' => $cardPortion > 0 ? 'pending' : ($scheduleStatus === 'paid' ? 'paid' : 'partial'),
                'payment_reference' => $paymentReference,
                'payment_authorization_id' => $authorization?->id,
                'provider' => $cardPortion > 0 ? 'paystack' : null,
                'provider_reference' => $providerReference,
                'amount_minor' => $cardPortion > 0 ? $this->repaymentService->amountToMinor($cardPortion) : null,
                'gateway_response' => $gatewayResponse,
                'metadata' => [
                    'payment_method' => $paymentMethod,
                    'wallet_amount' => $walletPortion,
                    'card_amount' => $cardPortion,
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                ],
            ]);

            // Update schedule
            if ($appliedAmount > 0 || $cardPortion === 0.0) {
                $schedule->update([
                'amount_paid' => $newAmountPaid,
                'balance_due' => $remainingDue,
                'status' => $scheduleStatus,
                ]);
            }

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
