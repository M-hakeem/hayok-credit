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
        $payment = DB::transaction(function () {
            $schedule = RepaymentSchedule::with(['loan.user', 'loan.user.paymentAuthorizations'])
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

            $authorization = $schedule->loan->user->paymentAuthorizations
                ->first(fn ($item) => $item->isUsable());
            if (! $authorization) {
                return null;
            }

            $reference = 'loan-repay-'.$schedule->loan_id.'-'.$schedule->id.'-'.str()->uuid();
            return LoanPayment::create([
                'loan_id' => $schedule->loan_id,
                'user_id' => $schedule->loan->user_id,
                'repayment_schedule_id' => $schedule->id,
                'payment_authorization_id' => $authorization->id,
                'due_date' => $schedule->due_date,
                'amount_due' => $schedule->balance_due,
                'amount_paid' => 0,
                'status' => 'pending',
                'provider' => 'paystack',
                'provider_reference' => $reference,
                'amount_minor' => $service->amountToMinor($schedule->balance_due),
                'attempt_count' => (int) $schedule->retry_count + 1,
                'last_attempt_at' => now(),
                'metadata' => [
                    'purpose' => 'loan_repayment',
                    'user_id' => $schedule->loan->user_id,
                    'loan_id' => $schedule->loan_id,
                    'loan_installment_id' => $schedule->id,
                ],
            ]);
        });

        if (! $payment) {
            return;
        }

        try {
            $authorization = $payment->paymentAuthorization;
            $response = $service->chargeAuthorization(
                $payment->user,
                $authorization,
                (int) $payment->amount_minor,
                $payment->provider_reference,
                $payment->metadata ?? []
            );

            $payment->update(['gateway_response' => $response]);
        } catch (Throwable $exception) {
            $service->markFailed($payment, $exception->getMessage());
        }
    }
}