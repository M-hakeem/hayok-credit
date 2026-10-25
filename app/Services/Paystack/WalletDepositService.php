<?php

namespace App\Services\Paystack;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletDeposit;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletDepositService
{
    public function __construct(private readonly PaystackClient $client) {}

    public function initialize(User $user, string|int|float $amount, ?string $description = null): array
    {
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required to deposit into the wallet.');
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            throw new RuntimeException('Wallet not found. Verify your NIN or BVN and create a wallet first.');
        }

        if ($wallet->currency !== 'NGN') {
            throw new RuntimeException('Wallet deposits are currently available in NGN only.');
        }

        $amountDecimal = number_format((float) $amount, 2, '.', '');
        $amountMinor = $this->amountToMinor($amountDecimal);
        $reference = 'wallet-deposit-'.$user->id.'-'.str()->uuid();
        $deposit = WalletDeposit::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => $reference,
            'amount' => $amountDecimal,
            'description' => $description,
        ]);

        try {
            $payload = [
                'email' => $user->email,
                'amount' => $amountMinor,
                'currency' => 'NGN',
                'reference' => $reference,
                'metadata' => [
                    'purpose' => 'wallet_deposit',
                    'user_id' => $user->id,
                    'wallet_deposit_id' => $deposit->id,
                    'amount_minor' => $amountMinor,
                ],
            ];
            $callbackUrl = config('paystack.wallet_deposit_callback_url');
            if (filled($callbackUrl)) {
                $payload['callback_url'] = $callbackUrl;
            }
            $transaction = $this->client->initializeTransaction($payload);
            if (blank($transaction['authorization_url'] ?? null)
                || ($transaction['reference'] ?? null) !== $reference) {
                throw new RuntimeException('Paystack did not return a valid checkout link for this wallet deposit.');
            }
        } catch (RuntimeException $exception) {
            $deposit->delete();
            throw $exception;
        }

        return [
            'reference' => $reference,
            'amount' => $amountDecimal,
            'currency' => 'NGN',
            'transaction' => $transaction,
        ];
    }

    public function verifyAndCredit(User $user, string $reference): array
    {
        $deposit = WalletDeposit::where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $deposit) {
            throw new RuntimeException('Wallet deposit not found for this user.');
        }

        if ($deposit->status === 'paid') {
            return $this->successfulDepositResponse($deposit);
        }

        return $this->creditVerifiedTransaction($this->client->verifyTransaction($reference));
    }

    public function creditVerifiedTransaction(array $transaction): array
    {
        $reference = $transaction['reference'] ?? null;
        $metadata = $transaction['metadata'] ?? [];
        $deposit = $reference
            ? WalletDeposit::where('reference', $reference)
                ->whereKey($metadata['wallet_deposit_id'] ?? 0)
                ->where('user_id', $metadata['user_id'] ?? 0)
                ->first()
            : null;

        if (! $deposit || ($metadata['purpose'] ?? null) !== 'wallet_deposit') {
            throw new RuntimeException('The Paystack transaction does not match a wallet deposit.');
        }

        $expectedAmountMinor = $this->amountToMinor((string) $deposit->amount);
        if (($transaction['status'] ?? null) !== 'success') {
            throw new RuntimeException('Paystack has not confirmed a successful wallet deposit.');
        }

        if (($transaction['currency'] ?? null) !== 'NGN'
            || (int) ($transaction['amount'] ?? 0) !== $expectedAmountMinor
            || (int) ($metadata['amount_minor'] ?? 0) !== $expectedAmountMinor) {
            throw new RuntimeException('The Paystack payment amount or currency does not match the wallet deposit.');
        }

        if (($transaction['customer']['email'] ?? null) !== $deposit->user->email) {
            throw new RuntimeException('The Paystack payment email does not match the wallet owner.');
        }

        return DB::transaction(function () use ($deposit, $transaction) {
            $deposit = WalletDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($deposit->status === 'paid') {
                return $this->successfulDepositResponse($deposit);
            }

            $wallet = Wallet::whereKey($deposit->wallet_id)->lockForUpdate()->firstOrFail();
            $walletTransaction = $wallet->credit(
                (float) $deposit->amount,
                $deposit->description ?? 'Wallet deposit via Paystack',
                $deposit->reference,
            );

            $deposit->update([
                'status' => 'paid',
                'wallet_transaction_id' => $walletTransaction->id,
                'paystack_transaction_id' => (string) ($transaction['id'] ?? ''),
                'channel' => $transaction['channel'] ?? null,
                'gateway_response' => $transaction,
            ]);

            return [
                'wallet' => $wallet->refresh(),
                'transaction' => $walletTransaction,
            ];
        });
    }

    private function successfulDepositResponse(WalletDeposit $deposit): array
    {
        $transaction = WalletTransaction::find($deposit->wallet_transaction_id);
        if (! $transaction) {
            throw new RuntimeException('The completed wallet deposit is missing its wallet transaction.');
        }

        return [
            'wallet' => $deposit->wallet->refresh(),
            'transaction' => $transaction,
        ];
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
