<?php

namespace App\Services\Paystack;

use App\Models\LoanDisbursement;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackTransferService
{
    public function __construct(private readonly PaystackClient $client)
    {
    }

    public function disburse(LoanDisbursement $disbursement): array
    {
        if ($disbursement->status !== 'pending') {
            throw new RuntimeException('This disbursement has already been processed or is in progress.');
        }

        $user = $disbursement->user;
        if (! $user || ! $user->bank_code || ! $user->bank_account_number || ! $user->bank_account_name) {
            throw new RuntimeException('A validated Nigerian bank account is required for disbursement.');
        }

        // Approval may have happened before the borrower connected a bank account.
        // Refresh the pending disbursement snapshot from the validated user profile.
        if (
            $disbursement->bank_code !== $user->bank_code
            || $disbursement->bank_account_number !== $user->bank_account_number
            || $disbursement->bank_account_name !== $user->bank_account_name
        ) {
            $disbursement->update([
                'bank_name' => $user->bank_name ?: 'Bank '.$user->bank_code,
                'bank_code' => $user->bank_code,
                'bank_account_number' => $user->bank_account_number,
                'bank_account_name' => $user->bank_account_name,
            ]);
            $disbursement->refresh();
        }

        $recipientCode = $disbursement->paystack_recipient_code;
        if (! $recipientCode) {
            $recipient = $this->client->createTransferRecipient([
                'type' => 'nuban',
                'name' => $disbursement->bank_account_name,
                'account_number' => $disbursement->bank_account_number,
                'bank_code' => $disbursement->bank_code,
                'currency' => 'NGN',
            ]);
            $recipientCode = $recipient['recipient_code'] ?? null;
            if (! $recipientCode) {
                throw new RuntimeException('Paystack did not return a transfer recipient code.');
            }
            $disbursement->update(['paystack_recipient_code' => $recipientCode]);
        }

        $reference = 'loan-disbursement-'.$disbursement->loan_id.'-'.$disbursement->id.'-'.str()->uuid();
        $response = $this->client->initiateTransfer([
            'source' => 'balance',
            'amount' => $this->amountToMinor($disbursement->amount),
            'recipient' => $recipientCode,
            'reference' => $reference,
            'reason' => 'Loan disbursement',
        ]);

        $disbursement->update([
            'status' => 'processing',
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'transfer_code' => $response['transfer_code'] ?? null,
            'gateway_response' => $response,
        ]);

        Log::info('Paystack loan disbursement initiated', [
            'disbursement_id' => $disbursement->id,
            'loan_id' => $disbursement->loan_id,
            'provider_reference' => $reference,
        ]);

        return $response + ['reference' => $reference];
    }

    private function amountToMinor(string|int|float $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);
        return ((int) $whole * 100) + (int) $fraction;
    }
}