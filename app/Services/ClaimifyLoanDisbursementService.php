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

        $disbursement->loadMissing('loan.user');
        $this->restoreBeneficiaryDetails($disbursement);

        $debitAccountNumber = DisbursementSetting::query()->find(1)?->debit_account_number;
        if (! $debitAccountNumber) {
            throw new RuntimeException('Set the reusable debit account number in dashboard settings before disbursing loans.');
        }

        if (! preg_match('/^\d{10}$/', $debitAccountNumber)) {
            throw new RuntimeException('The configured debit account number must contain 10 digits.');
        }

        if (! $disbursement->bank_account_number) {
            throw new RuntimeException('The beneficiary account number is missing. Add the destination account number to the loan payout details or borrower profile.');
        }

        if (! $disbursement->bank_code) {
            $bankCode = $this->resolveBankCode((string) $disbursement->bank_name);
            if (! $bankCode) {
                throw new RuntimeException('The beneficiary bank code is missing. Verify the destination bank name and bank code before disbursing.');
            }

            $disbursement->update(['bank_code' => $bankCode]);
            $disbursement->refresh();
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

        $nameEnquiryReference = $this->findNameEnquiryReference($nameEnquiry);

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
            $message = $this->responseErrorMessage($transfer);
            $disbursement->update([
                'status' => 'failed',
                'failure_reason' => $message,
                'gateway_response' => $transfer,
            ]);

            throw new RuntimeException($message);
        }

        $disbursement->update([
            'gateway_response' => $transfer,
        ]);

        return $transfer + ['reference' => $reference];
    }

    private function restoreBeneficiaryDetails(LoanDisbursement $disbursement): void
    {
        $loan = $disbursement->loan;
        if (! $loan) {
            return;
        }

        $payoutAccount = $loan->payout_account ?? [];
        $user = $loan->user;
        $updates = [];

        foreach (['bank_name', 'bank_account_number', 'bank_account_name', 'bank_code'] as $field) {
            $payoutValue = $payoutAccount[$field] ?? null;
            $fallback = filled($payoutValue) ? $payoutValue : $user?->{$field};

            if (blank($disbursement->{$field}) && filled($fallback)) {
                $updates[$field] = $fallback;
            }
        }

        if ($updates !== []) {
            $disbursement->update($updates);
            $disbursement->refresh();
        }
    }

    private function resolveBankCode(string $bankName): ?string
    {
        if (blank($bankName)) {
            return null;
        }

        $response = $this->claimify->banks();
        $banks = data_get($response, 'data.banks')
            ?? data_get($response, 'data')
            ?? data_get($response, 'banks')
            ?? [];

        if (! is_array($banks)) {
            return null;
        }

        foreach ($banks as $bank) {
            if (! is_array($bank)) {
                continue;
            }

            $name = $bank['name'] ?? $bank['bankName'] ?? $bank['bank_name'] ?? null;
            $code = $bank['code'] ?? $bank['bankCode'] ?? $bank['bank_code'] ?? null;

            if (
                is_string($name)
                && mb_strtolower(trim($name)) === mb_strtolower(trim($bankName))
                && (is_string($code) || is_numeric($code))
                && (string) $code !== ''
            ) {
                return (string) $code;
            }
        }

        return null;
    }

    private function findNameEnquiryReference(array $response): ?string
    {
        $referenceKeys = [
            'nameenquiryreference',
            'nameenquiryref',
            'sessionid',
            'sessionreference',
            'reference',
        ];

        $search = function (array $data) use (&$search, $referenceKeys): ?string {
            foreach ($referenceKeys as $key) {
                foreach ($data as $responseKey => $value) {
                    if (
                        is_string($responseKey)
                        && preg_replace('/[^a-z]/', '', strtolower($responseKey)) === $key
                        && (is_string($value) || is_numeric($value))
                        && (string) $value !== ''
                    ) {
                        return (string) $value;
                    }
                }
            }

            foreach ($data as $value) {
                if (is_array($value)) {
                    $reference = $search($value);
                    if ($reference !== null) {
                        return $reference;
                    }
                }
            }

            return null;
        };

        return $search($response);
    }

    private function isFailedResponse(array $response): bool
    {
        foreach ($this->responseArrays($response) as $data) {
            if (
                data_get($data, 'success') === false
                || data_get($data, 'status') === false
                || data_get($data, 'requestSuccessful') === false
                || in_array(strtolower((string) data_get($data, 'status')), ['error', 'failed', 'failure'], true)
            ) {
                return true;
            }

            $statusCode = data_get($data, 'statusCode');
            if (is_numeric($statusCode) && (int) $statusCode >= 400) {
                return true;
            }

            $responseCode = data_get($data, 'responseCode');
            if (
                (is_string($responseCode) || is_numeric($responseCode))
                && ! in_array((string) $responseCode, ['0', '00', '000', '200'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function responseErrorMessage(array $response): string
    {
        foreach ($this->responseArrays($response) as $data) {
            $message = data_get($data, 'message');
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return 'Claimify could not process the bank transfer.';
    }

    private function responseArrays(array $response): array
    {
        $arrays = [$response];
        foreach ($response as $value) {
            if (is_array($value)) {
                $arrays = array_merge($arrays, $this->responseArrays($value));
            }
        }

        return $arrays;
    }
}
