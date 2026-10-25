<?php

namespace App\Services\Paystack;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WalletWithdrawalService
{
    public function __construct(private readonly PaystackClient $client) {}

    public function requestWithdrawal(User $user, string|int|float $amount, ?string $description = null): array
    {
        if (! $user->bank_connected_at || ! $user->bank_code || ! $user->bank_account_number) {
            throw new RuntimeException('Connect and validate a bank account before withdrawing funds.');
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            throw new RuntimeException('Wallet not found. Verify your NIN or BVN and create a wallet first.');
        }

        if ($wallet->currency !== 'NGN') {
            throw new RuntimeException('Wallet withdrawals are currently available in NGN only.');
        }

        $amountDecimal = number_format((float) $amount, 2, '.', '');
        $amountMinor = $this->amountToMinor($amountDecimal);
        try {
            $resolved = $this->client->resolveAccountNumber($user->bank_account_number, $user->bank_code);
            $accountName = $resolved['account_name'] ?? null;
            if (blank($accountName)) {
                throw new RuntimeException('Paystack could not validate the connected bank account.');
            }

            $recipient = $this->client->createTransferRecipient([
                'type' => 'nuban',
                'name' => $accountName,
                'account_number' => $user->bank_account_number,
                'bank_code' => $user->bank_code,
                'currency' => 'NGN',
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Paystack bank validation failed during wallet withdrawal', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Could not validate the connected bank account. Please try again.');
        }

        $recipientCode = $recipient['recipient_code'] ?? null;
        if (blank($recipientCode)) {
            throw new RuntimeException('Paystack did not return a transfer recipient code.');
        }

        $reference = 'wallet-withdrawal-'.$user->id.'-'.str()->uuid();
        $withdrawal = DB::transaction(function () use ($user, $wallet, $amountDecimal, $description, $reference, $recipientCode, $accountName, $resolved) {
            $lockedWallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $lockedWallet->debit((float) $amountDecimal, $description ?? 'Wallet withdrawal', $reference);

            return Withdrawal::create([
                'user_id' => $user->id,
                'wallet_id' => $lockedWallet->id,
                'amount' => $amountDecimal,
                'bank_name' => $resolved['bank_name'] ?? $user->bank_name ?? 'Bank '.$user->bank_code,
                'bank_account_number' => $user->bank_account_number,
                'bank_account_name' => $accountName,
                'bank_code' => $user->bank_code,
                'status' => 'processing',
                'paystack_reference' => $reference,
                'paystack_recipient_code' => $recipientCode,
            ]);
        });

        try {
            $transfer = $this->client->initiateTransfer([
                'source' => 'balance',
                'amount' => $amountMinor,
                'recipient' => $recipientCode,
                'reference' => $reference,
                'reason' => $description ?: 'Wallet withdrawal',
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Paystack wallet withdrawal result is awaiting confirmation', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $reference,
                'exception' => $exception->getMessage(),
            ]);

            return $this->withdrawalResponse($withdrawal, 'Withdrawal submitted; transfer status is being confirmed.', 202);
        } catch (RuntimeException $exception) {
            $this->refund($withdrawal, $exception->getMessage());

            throw new RuntimeException('Paystack could not start the transfer. The withdrawn amount has been returned to your wallet.');
        }

        $withdrawal->update([
            'transfer_code' => $transfer['transfer_code'] ?? null,
            'gateway_response' => $transfer,
        ]);

        if (in_array($transfer['status'] ?? null, ['success', 'failed', 'reversed'], true)) {
            $this->settle($reference, (string) $transfer['status'], $transfer);
            $withdrawal->refresh();
        }

        return $this->withdrawalResponse(
            $withdrawal,
            $withdrawal->status === 'completed'
                ? 'Withdrawal transferred to your bank account.'
                : ($withdrawal->status === 'failed'
                    ? 'Paystack could not complete the transfer; the amount has been returned to your wallet.'
                    : 'Withdrawal submitted; transfer status is being confirmed.'),
            $withdrawal->status === 'processing' ? 202 : 200,
        );
    }

    public function verifyWithdrawal(User $user, string $reference): array
    {
        $withdrawal = Withdrawal::where('paystack_reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $withdrawal) {
            throw new RuntimeException('Withdrawal not found for this user.');
        }

        if ($withdrawal->status !== 'processing') {
            return $this->withdrawalResponse($withdrawal, $this->statusMessage($withdrawal->status), 200);
        }

        try {
            $transfer = $this->client->verifyTransfer($reference);
        } catch (ConnectionException $exception) {
            Log::warning('Paystack wallet withdrawal verification unavailable', [
                'withdrawal_id' => $withdrawal->id,
                'reference' => $reference,
                'exception' => $exception->getMessage(),
            ]);

            return $this->withdrawalResponse($withdrawal, 'Transfer status is still being confirmed.', 202);
        }

        $status = $transfer['status'] ?? 'pending';
        if (in_array($status, ['success', 'failed', 'reversed'], true)) {
            $this->settle($reference, $status, $transfer);
            $withdrawal->refresh();
        }

        return $this->withdrawalResponse(
            $withdrawal,
            $this->statusMessage($withdrawal->status),
            $withdrawal->status === 'processing' ? 202 : 200,
        );
    }

    public function settleWebhook(array $data, string $eventName): void
    {
        $reference = $data['reference'] ?? null;
        if (! $reference) {
            return;
        }

        $status = match ($eventName) {
            'transfer.success' => 'success',
            'transfer.failed' => 'failed',
            'transfer.reversed' => 'reversed',
            default => null,
        };

        if ($status) {
            $this->settle($reference, $status, $data);
        }
    }

    private function settle(string $reference, string $providerStatus, array $response): void
    {
        DB::transaction(function () use ($reference, $providerStatus, $response) {
            $withdrawal = Withdrawal::where('paystack_reference', $reference)->lockForUpdate()->first();
            if (! $withdrawal) {
                return;
            }

            if ($withdrawal->status === 'completed' && $providerStatus === 'reversed') {
                $this->refund($withdrawal, $response['reason'] ?? 'Paystack transfer was reversed.', $response, true);

                return;
            }

            if ($withdrawal->status !== 'processing') {
                return;
            }

            if ($providerStatus === 'success') {
                $withdrawal->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'gateway_response' => $response,
                ]);

                return;
            }

            if (in_array($providerStatus, ['failed', 'reversed'], true)) {
                $this->refund($withdrawal, $response['reason'] ?? 'Paystack transfer '.$providerStatus.'.', $response);
            }
        });
    }

    private function refund(Withdrawal $withdrawal, string $reason, array $response = [], bool $allowCompleted = false): void
    {
        DB::transaction(function () use ($withdrawal, $reason, $response, $allowCompleted) {
            $lockedWithdrawal = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($lockedWithdrawal->status !== 'processing'
                && ! ($allowCompleted && $lockedWithdrawal->status === 'completed')) {
                return;
            }

            $wallet = Wallet::whereKey($lockedWithdrawal->wallet_id)->lockForUpdate()->firstOrFail();
            $wallet->credit(
                (float) $lockedWithdrawal->amount,
                'Refund for failed wallet withdrawal',
                'withdrawal-refund-'.$lockedWithdrawal->id,
            );

            $lockedWithdrawal->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'gateway_response' => $response ?: $lockedWithdrawal->gateway_response,
                'processed_at' => now(),
            ]);
        });
    }

    private function withdrawalResponse(Withdrawal $withdrawal, string $message, int $httpStatus): array
    {
        return [
            'message' => $message,
            'http_status' => $httpStatus,
            'data' => [
                'withdrawal' => $withdrawal->fresh(),
                'wallet' => $withdrawal->wallet()->first(),
            ],
        ];
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'completed' => 'Withdrawal transferred to your bank account.',
            'failed' => 'Paystack could not complete the transfer; the amount has been returned to your wallet.',
            default => 'Transfer status is still being confirmed.',
        };
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
