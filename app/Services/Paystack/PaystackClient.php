<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackClient
{
    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('paystack.secret_key'))
            ->timeout((int) config('paystack.timeout', 20));
    }

    protected function request(string $method, string $uri, array $payload = []): array
    {
        $response = $this->http()->{$method}(rtrim(config('paystack.base_url'), '/').'/'.$uri, $payload);
        $body = $response->json() ?: [];

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            Log::warning('Paystack API request failed', [
                'operation' => $uri,
                'http_status' => $response->status(),
                'message' => $body['message'] ?? 'Unknown Paystack error',
            ]);

            throw new RuntimeException($body['message'] ?? 'Paystack request failed.');
        }

        return $body['data'] ?? [];
    }

    public function initializeTransaction(array $payload): array
    {
        return $this->request('post', 'transaction/initialize', $payload);
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->request('get', 'transaction/verify/'.rawurlencode($reference));
    }

    public function chargeAuthorization(array $payload): array
    {
        return $this->request('post', 'transaction/charge_authorization', $payload);
    }

    public function createTransferRecipient(array $payload): array
    {
        return $this->request('post', 'transferrecipient', $payload);
    }

    public function initiateTransfer(array $payload): array
    {
        return $this->request('post', 'transfer', $payload);
    }

    public function finalizeTransfer(string $transferCode, string $otp): array
    {
        return $this->request('post', 'transfer/finalize_transfer', [
            'transfer_code' => $transferCode,
            'otp' => $otp,
        ]);
    }

    public function verifyTransfer(string $reference): array
    {
        return $this->request('get', 'transfer/verify/'.rawurlencode($reference));
    }

    public function listBanks(): array
    {
        return $this->request('get', 'bank?country=nigeria&currency=NGN');
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        return $this->request('get', 'bank/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
            'bank_code' => $bankCode,
        ]);
    }
}