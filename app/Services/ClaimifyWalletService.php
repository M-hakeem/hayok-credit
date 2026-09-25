<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

    protected function token(): string
    {
        return Cache::remember('claimify_wallet.access_token', now()->addMinutes(50), function (): string {
            $email = config('services.claimify_wallet.email');
            $password = config('services.claimify_wallet.password');

            if (! $email || ! $password) {
                throw new \RuntimeException('Claimify wallet credentials are not configured.');
            }

            $response = Http::baseUrl(rtrim($this->baseUrl(), '/'))
                ->acceptJson()
                ->timeout($this->timeout())
                ->post('/auth/login', ['email' => $email, 'password' => $password])
                ->throw()
                ->json();

            $token = data_get($response, 'data.access_token')
                ?? data_get($response, 'data.token')
                ?? data_get($response, 'access_token')
                ?? data_get($response, 'token');

            if (! is_string($token) || $token === '') {
                throw new \UnexpectedValueException('Claimify login response did not include an access token.');
            }

            $expiresIn = data_get($response, 'data.expires_in') ?? data_get($response, 'expires_in');
            if (is_numeric($expiresIn) && (int) $expiresIn > 60) {
                Cache::put('claimify_wallet.access_token', $token, now()->addSeconds((int) $expiresIn - 60));
            }

            return $token;
        });
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
