<?php

namespace App\Services\Paystack;

use App\Models\PaymentAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentAuthorizationService
{
    public function __construct(private readonly PaystackClient $client)
    {
    }

    public function initialize(User $user): array
    {
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required before authorizing a card.');
        }

        $reference = 'card-auth-'.$user->id.'-'.str()->uuid();

        return [
            'reference' => $reference,
            'transaction' => $this->client->initializeTransaction([
                'email' => $user->email,
                'amount' => (int) config('paystack.authorization_amount_kobo', 10000),
                'currency' => 'NGN',
                'reference' => $reference,
                'metadata' => [
                    'user_id' => $user->id,
                    'purpose' => 'card_authorization',
                    'internal_reference' => $reference,
                ],
            ]),
        ];
    }

    public function verifyAndStore(User $user, string $reference): PaymentAuthorization
    {
        $transaction = $this->client->verifyTransaction($reference);

        if (($transaction['status'] ?? null) !== 'success') {
            throw new RuntimeException('Paystack did not report a successful card authorization.');
        }

        $metadata = $transaction['metadata'] ?? [];
        if ((string) ($metadata['purpose'] ?? '') !== 'card_authorization' || (int) ($metadata['user_id'] ?? 0) !== (int) $user->id) {
            throw new RuntimeException('The Paystack transaction does not belong to this card authorization request.');
        }

        if (($transaction['customer']['email'] ?? null) !== $user->email) {
            throw new RuntimeException('The Paystack authorization email does not match the user email.');
        }

        $authorization = $transaction['authorization'] ?? [];
        if (($authorization['reusable'] ?? false) !== true || blank($authorization['authorization_code'] ?? null)) {
            throw new RuntimeException('Paystack returned a non-reusable card authorization.');
        }

        $code = $authorization['authorization_code'];

        return DB::transaction(function () use ($user, $transaction, $authorization, $code) {
            return PaymentAuthorization::updateOrCreate(
                ['authorization_code_hash' => hash('sha256', $code)],
                [
                    'user_id' => $user->id,
                    'paystack_customer_code' => $transaction['customer']['customer_code'] ?? null,
                    'authorization_code' => $code,
                    'signature' => $authorization['signature'] ?? null,
                    'email' => $transaction['customer']['email'],
                    'card_type' => $authorization['card_type'] ?? null,
                    'brand' => $authorization['brand'] ?? null,
                    'last4' => $authorization['last4'] ?? null,
                    'exp_month' => $authorization['exp_month'] ?? null,
                    'exp_year' => $authorization['exp_year'] ?? null,
                    'bank' => $authorization['bank'] ?? null,
                    'country_code' => $authorization['country_code'] ?? null,
                    'channel' => $transaction['channel'] ?? null,
                    'reusable' => true,
                    'status' => 'active',
                    'metadata' => ['authorization_reference' => $transaction['reference'] ?? null],
                ]
            );
        });
    }

    public function deactivate(PaymentAuthorization $authorization): void
    {
        $authorization->update(['status' => 'revoked']);
        Log::info('Paystack card authorization revoked', [
            'authorization_id' => $authorization->id,
            'user_id' => $authorization->user_id,
        ]);
    }
}