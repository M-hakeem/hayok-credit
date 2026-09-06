<?php

namespace App\Services\Paystack;

use App\Models\LoanPayment;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use App\Models\RepaymentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackWebhookService
{
    public function __construct(
        private readonly PaystackClient $client,
        private readonly PaymentAuthorizationService $authorizationService,
    ) {
    }

    public function validSignature(string $payload, ?string $signature): bool
    {
        // Paystack signs webhook payloads with the Paystack secret key.
        $secret = config('paystack.secret_key');
        $signature = $signature !== null ? trim($signature) : null;

        return filled($secret) && filled($signature)
            && hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    public function handle(string $rawPayload, array $payload): void
    {
        $eventId = hash('sha256', $rawPayload);
        $event = PaystackWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'event' => $payload['event'] ?? 'unknown',
                'reference' => data_get($payload, 'data.reference'),
                'payload' => $payload,
            ]
        );

        if ($event->processed_at) {
            return;
        }

        DB::transaction(function () use ($event, $payload) {
            $event->update(['processed_at' => now()]);
            $eventName = $payload['event'] ?? null;

            if ($eventName === 'charge.success') {
                $this->handleSuccessfulCharge($payload['data'] ?? []);
            }

            if (in_array($eventName, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
                $this->handleTransfer($payload['data'] ?? [], $eventName);
            }
        });
    }

    private function handleSuccessfulCharge(array $data): void
    {
        $reference = $data['reference'] ?? null;
        if (! $reference) {
            return;
        }

        $verified = $this->client->verifyTransaction($reference);
        if (($verified['status'] ?? null) !== 'success') {
            Log::warning('Paystack webhook transaction was not successful after verification', [
                'reference' => $reference,
                'verified_status' => $verified['status'] ?? null,
            ]);

            // A signed but unsuccessful event, including dashboard test payloads,
            // must be acknowledged without changing financial state.
            return;
        }

        $metadata = $verified['metadata'] ?? [];
        if (($metadata['purpose'] ?? null) === 'card_authorization') {
            $user = User::find((int) ($metadata['user_id'] ?? 0));
            if ($user) {
                $this->authorizationService->verifyAndStore($user, $reference);
            }
            return;
        }

        if (($metadata['purpose'] ?? null) === 'loan_repayment') {
            $payment = LoanPayment::where('provider_reference', $reference)->lockForUpdate()->first();
            if (! $payment || $payment->status === 'paid') {
                return;
            }

            $payment->update([
                'status' => 'paid',
                'amount_paid' => $payment->amount_due,
                'paid_at' => now(),
                'provider_transaction_id' => (string) ($verified['id'] ?? $data['id'] ?? ''),
                'gateway_response' => $verified,
                'failure_reason' => null,
            ]);

            $schedule = $payment->repaymentSchedule()->lockForUpdate()->first();
            if ($schedule) {
                $newAmountPaid = round((float) $schedule->amount_paid + (float) $payment->amount_due, 2);
                $balanceDue = max(0, round((float) $schedule->total_due - $newAmountPaid, 2));
                $schedule->update([
                    'amount_paid' => $newAmountPaid,
                    'balance_due' => $balanceDue,
                    'status' => $balanceDue > 0 ? 'partial' : 'paid',
                    'paid_at' => $balanceDue > 0 ? null : now(),
                ]);
            }
        }
    }

    private function handleTransfer(array $data, string $eventName): void
    {
        $reference = $data['reference'] ?? null;
        if (! $reference) {
            return;
        }

        $disbursement = \App\Models\LoanDisbursement::where('provider_reference', $reference)->lockForUpdate()->first();
        if (! $disbursement) {
            return;
        }

        $disbursement->update([
            'status' => $eventName === 'transfer.success' ? 'disbursed' : 'failed',
            'transfer_code' => $data['transfer_code'] ?? $disbursement->transfer_code,
            'failure_reason' => $eventName === 'transfer.success' ? null : ($data['reason'] ?? 'Paystack transfer failed.'),
            'gateway_response' => $data,
            'disbursed_at' => $eventName === 'transfer.success' ? now() : null,
        ]);

        if ($eventName === 'transfer.success') {
            $loan = $disbursement->loan;
            if ($loan && $loan->repaymentSchedules()->doesntExist()) {
                $term = (int) $loan->term_months;
                $principal = round($loan->amount_requested / $term, 2);
                $interest = round($loan->total_interest / $term, 2);

                for ($number = 1; $number <= $term; $number++) {
                    $principalAmount = $number === $term
                        ? round($loan->amount_requested - ($principal * ($term - 1)), 2)
                        : $principal;
                    $interestAmount = $number === $term
                        ? round($loan->total_interest - ($interest * ($term - 1)), 2)
                        : $interest;
                    $totalDue = round($principalAmount + $interestAmount, 2);

                    RepaymentSchedule::create([
                        'loan_id' => $loan->id,
                        'installment_number' => $number,
                        'due_date' => Carbon::parse($disbursement->disbursed_at)->addMonths($number)->toDateString(),
                        'principal_amount' => $principalAmount,
                        'interest_amount' => $interestAmount,
                        'penalty_amount' => 0,
                        'total_due' => $totalDue,
                        'amount_paid' => 0,
                        'balance_due' => $totalDue,
                        'status' => 'pending',
                    ]);
                }
            }
            $loan?->update(['status' => 'active']);
        }
    }
}