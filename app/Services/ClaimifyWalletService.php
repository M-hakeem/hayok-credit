<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ClaimifyWalletService
{
    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->acceptJson()
            ->withToken($this->token())
            ->timeout($this->timeout());
    }

    protected function baseUrl(): string
    {
        return (string) config('services.claimify_wallet.base_url');
    }

    protected function token(): ?string
    {
        return config('services.claimify_wallet.token');
    }

    protected function timeout(): int
    {
        return (int) config('services.claimify_wallet.timeout', 20);
    }

    /** @throws RequestException */
    public function banks(): array
    {
        return $this->client()->post('/auth/wallet/banks')->throw()->json();
    }

    /** @throws RequestException */
    public function nameInquiry(string $bankCode, string $accountNumber): array
    {
        return $this->client()->asMultipart()->post('/auth/wallet/name-inquiry', [
            'bankCode' => $bankCode,
            'accountNumber' => $accountNumber,
        ])->throw()->json();
    }

    /** @throws RequestException */
    public function initiateVerification(string $type, string $number): array
    {
        return $this->client()->post('/auth/wallet/initiate_verification', [
            'type' => $type,
            'number' => $number,
        ])->throw()->json();
    }

    /** @throws RequestException */
    public function validateVerification(string $identityId, string $type, string $otp): array
    {
        return $this->client()->post('/auth/wallet/validate_verification', [
            'identityId' => $identityId,
            'type' => $type,
            'otp' => $otp,
        ])->throw()->json();
    }

    /** @throws RequestException */
    public function createCustomer(array $payload): array
    {
        return $this->client()->post('/auth/wallet/create_customer', $payload)->throw()->json();
    }
}
