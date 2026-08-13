<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class FirstCentralService
{
    private function client(): PendingRequest
    {
        $client = Http::baseUrl(rtrim(config('services.first_central.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.first_central.timeout', 20));

        if ($token = config('services.first_central.token')) {
            return $client->withToken($token);
        }

        if ($apiKey = config('services.first_central.api_key')) {
            return $client->withHeaders([
                'X-API-KEY' => $apiKey,
            ]);
        }

        return $client;
    }

    /** @throws RequestException */
    public function banks(): array
    {
        return $this->client()
            ->get('/banks')
            ->throw()
            ->json();
    }

    /** @throws RequestException */
    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        return $this->client()
            ->post('/bank/account/resolve', [
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
            ])
            ->throw()
            ->json();
    }

    /** @throws RequestException */
    public function transfer(array $payload): array
    {
        return $this->client()
            ->post('/transfer', $payload)
            ->throw()
            ->json();
    }
}
