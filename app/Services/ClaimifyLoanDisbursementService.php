<?php

namespace App\Services;

use App\Models\DisbursementSetting;
use App\Models\LoanDisbursement;
use RuntimeException;

class ClaimifyLoanDisbursementService
{
    public function __construct(private readonly ClaimifyWalletService $claimify)
    {
    }

    public function disburse(LoanDisbursement $disbursement): array
    {
        if ($disbursement->status !== 'pending') {
            throw new RuntimeException('This disbursement has already been processed or is in progress.');
        }

        $debitAccountNumber = DisbursementSetting::query()->find(1)?->debit_account_number;
        if (! $debitAccountNumber) {
            throw new RuntimeException('Set the reusable debit account number in dashboard settings before disbursing loans.');
        }

        if (! preg_match('/^\d{10}$/', $debitAccountNumber)) {
            throw new RuntimeException('The configured debit account number must contain 10 digits.');
        }

        if (! $disbursement->bank_code || ! $disbursement->bank_account_number) {
            throw new RuntimeException('A validated Nigerian bank account is required for disbursement.');
        }

        $reference = $disbursement->provider_reference
            ?: 'loan-disbursement-'.$disbursement->loan_id.'-'.$disbursement->id.'-'.str()->uuid();

        $disbursement->update([
            'provider' => 'claimify',
            'provider_reference' => $reference,
        ]);

        $nameEnquiry = $this->claimify->nameInquiry(
            (string) $disbursement->bank_code,
            (string) $disbursement->bank_account_number
        );

        if ($this->isFailedResponse($nameEnquiry)) {
            throw new RuntimeException(data_get($nameEnquiry, 'message', 'Claimify could not verify the beneficiary bank account.'));
        }

        $nameEnquiryReference = data_get($nameEnquiry, 'data.nameEnquiryReference')
            ?? data_get($nameEnquiry, 'data.name_enquiry_reference')
            ?? data_get($nameEnquiry, 'nameEnquiryReference')
            ?? data_get($nameEnquiry, 'name_enquiry_reference')
            ?? data_get($nameEnquiry, 'data.reference')
            ?? data_get($nameEnquiry, 'reference');

        if (! is_string($nameEnquiryReference) || $nameEnquiryReference === '') {
            throw new RuntimeException('Claimify did not return a name enquiry reference for the beneficiary account.');
        }

        $transfer = $this->claimify->transfer([
            'paymentReference' => $reference,
            'nameEnquiryReference' => $nameEnquiryReference,
            'debitAccountNumber' => $debitAccountNumber,
            'beneficiaryBankCode' => (string) $disbursement->bank_code,
            'beneficiaryAccountNumber' => (string) $disbursement->bank_account_number,
            'narration' => 'Loan disbursement',
            'amount' => round((float) $disbursement->amount, 2),
            'saveBeneficiary' => true,
            'date' => '',
            'currency' => 'NGN',
            'userReward' => false,
        ]);

        if ($this->isFailedResponse($transfer)) {
            throw new RuntimeException(data_get($transfer, 'message', 'Claimify could not process the bank transfer.'));
        }

        $disbursement->update([
            'gateway_response' => $transfer,
        ]);

        return $transfer + ['reference' => $reference];
    }

    private function isFailedResponse(array $response): bool
    {
        return data_get($response, 'success') === false
            || data_get($response, 'status') === false
            || data_get($response, 'requestSuccessful') === false
            || in_array(strtolower((string) data_get($response, 'status')), ['error', 'failed', 'failure'], true);
    }
}
