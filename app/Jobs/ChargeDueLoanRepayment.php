<?php

namespace App\Jobs;

use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Services\Paystack\RepaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChargeDueLoanRepayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $scheduleId)
    {
    }

    public function handle(RepaymentService $service): void
    {
        $payment = DB::transaction(function () use ($service) {
            $schedule = RepaymentSchedule::with(['loan.user.wallet', 'loan.user.paymentAuthorizations'])
                ->lockForUpdate()->find($this->scheduleId);

            if (! $schedule || ! $schedule->loan || $schedule->loan->status !== 'active' || $schedule->balance_due <= 0) {
                return null;
            }
            if ($schedule->next_attempt_at && $schedule->next_attempt_at->isFuture()) {
                return null;
            }
            if ($schedule->payments()->whereIn('status', ['pending', 'paid'])->exists()) {
                return null;
            }

            $balanceDue = (float) $schedule->balance_due;
            $wallet = $schedule->loan->user->wallet;
            $walletPortion = min($balanceDue, (float) ($wallet?->balance ?? 0));
            $cardPortion = round($balanceDue - $walletPortion, 2);
            $authorization = $cardPortion > 0
                ? $schedule->loan->user->paymentAuthorizations->first(fn ($item) => $item->isUsable())
                : null;

            if ($cardPortion > 0 && ! $authorization) {
                $schedule->update([
                    'retry_count' => (int) $schedule->retry_count + 1,
                    'last_attempt_at' => now(),
                    'next_attempt_at' => now()->addDay(),
                    'failure_reason' => 'Insufficient wallet balance and no usable card.',
                ]);
                return null;
            }

            $reference = 'loan-repay-'.$schedule->loan_id.'-'.$schedule->id.'-'.str()->uuid();
            if ($walletPortion > 0) {
                $wallet->debit($walletPortion, 'Loan repayment', $reference);
            }

            if ($cardPortion > 0 && $walletPortion > 0) {
                $schedule->update([
                    'amount_paid' => round((float) $schedule->amount_paid + $walletPortion, 2),
                    'balance_due' => $cardPortion,
                    'status' => 'partial',
                    'last_attempt_at' => now(),
                    'failure_reason' => null,
                ]);
            }

            return LoanPayment::create([
                'loan_id' => $schedule->loan_id,
                'user_id' => $schedule->loan->user_id,
                'repayment_schedule_id' => $schedule->id,
                'payment_authorization_id' => $authorization?->id,
                'due_date' => $schedule->due_date,
                'amount_due' => $cardPortion > 0 ? $cardPortion : $balanceDue,
                'amount_paid' => $walletPortion,
                'paid_at' => $cardPortion > 0 ? null : now(),
                'status' => $cardPortion > 0 ? 'pending' : 'paid',
                'payment_reference' => $reference,
                'provider' => $cardPortion > 0 ? 'paystack' : null,
                'provider_reference' => $cardPortion > 0 ? $reference : null,
                'amount_minor' => $cardPortion > 0 ? $service->amountToMinor($cardPortion) : null,
                'attempt_count' => (int) $schedule->retry_count + 1,
                'last_attempt_at' => now(),
                'metadata' => [
                    'purpose' => 'loan_repayment',
                    'payment_method' => $cardPortion > 0 ? ($walletPortion > 0 ? 'wallet_and_card' : 'card') : 'wallet',
                    'wallet_amount' => $walletPortion,
                    'card_amount' => $cardPortion,
                    'user_id' => $schedule->loan->user_id,
                    'loan_id' => $schedule->loan_id,
                    'loan_installment_id' => $schedule->id,
                ],
            ]);
        });

        if (! $payment) {
            return;
        }

        if ($payment->status === 'pending') {
            try {
                $response = $service->chargeAuthorization(
                    $payment->user,
                    $payment->paymentAuthorization,
                    (int) $payment->amount_minor,
                    $payment->provider_reference,
                    $payment->metadata ?? []
                );
                $payment->update(['gateway_response' => $response]);
            } catch (Throwable $exception) {
                $service->markFailed($payment, $exception->getMessage());
            }
            return;
        }

        DB::transaction(function () use ($payment) {
            $schedule = RepaymentSchedule::with('loan')->lockForUpdate()->find($payment->repayment_schedule_id);
            if (! $schedule) {
                return;
            }
            $paid = round((float) $schedule->amount_paid + (float) $payment->amount_paid, 2);
            $balance = max(0, round((float) $schedule->total_due - $paid, 2));
            $schedule->update([
                'amount_paid' => $paid,
                'balance_due' => $balance,
                'status' => $balance > 0 ? 'partial' : 'paid',
                'paid_at' => $balance > 0 ? null : now(),
                'failure_reason' => null,
                'next_attempt_at' => null,
            ]);
            if ($balance === 0.0 && $schedule->loan->repaymentSchedules()->whereIn('status', ['pending', 'partial'])->count() === 0) {
                $schedule->loan->update(['status' => 'completed']);
            }
        });
    }
}
