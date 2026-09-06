<?php

namespace App\Services\Paystack;

use App\Models\LoanPayment;
use App\Models\PaymentAuthorization;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RepaymentService
{
    public function __construct(private readonly PaystackClient $client)
    {
    }

    public function chargeAuthorization(User $user, PaymentAuthorization $authorization, int $amount, string $reference, array $metadata = []): array
    {
        if ($authorization->user_id !== $user->id || ! $authorization->isUsable()) {
            throw new RuntimeException('The payment authorization is not available for this user.');
        }

        return $this->client->chargeAuthorization([
            'authorization_code' => $authorization->authorization_code,
            'email' => $authorization->email,
            'amount' => $amount,
            'currency' => 'NGN',
            'reference' => $reference,
            'metadata' => $metadata,
        ]);
    }

    public function amountToMinor(string|int|float $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);

        return ((int) $whole * 100) + (int) $fraction;
    }

    public function markFailed(LoanPayment $payment, string $reason, array $response = []): void
    {
        $maxAttempts = (int) config('paystack.repayment_max_attempts', 3);
        $attempts = (int) $payment->attempt_count;
        $final = $attempts >= $maxAttempts;

        $payment->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'gateway_response' => $response,
            'next_retry_at' => $final ? null : now()->addHours((int) config('paystack.repayment_retry_delay_hours', 24)),
        ]);

        $schedule = $payment->repaymentSchedule;
        if ($schedule) {
            $schedule->update([
                'status' => $final ? 'overdue' : $schedule->status,
                'retry_count' => $attempts,
                'last_attempt_at' => now(),
                'next_attempt_at' => $final ? null : $payment->next_retry_at,
                'failure_reason' => $reason,
            ]);
        }

        Log::warning('Paystack loan repayment failed', [
            'payment_id' => $payment->id,
            'loan_id' => $payment->loan_id,
            'installment_id' => $payment->repayment_schedule_id,
            'provider_reference' => $payment->provider_reference,
            'attempt_count' => $attempts,
        ]);
    }
}