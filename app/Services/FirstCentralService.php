<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FirstCentralService
{
    private const DATA_TICKET_CACHE_KEY = 'first_central.data_ticket';

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.first_central.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.first_central.timeout', 20));
    }

    private function extractDataTicket(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['DataTicket']) && is_string($payload['DataTicket']) && $payload['DataTicket'] !== '') {
            return $payload['DataTicket'];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $ticket = $this->extractDataTicket($payload['data']);
            if ($ticket) {
                return $ticket;
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $ticket = $this->extractDataTicket($value);
                if ($ticket) {
                    return $ticket;
                }
            }
        }

        return null;
    }

    private function login(): string
    {
        $username = config('services.first_central.username');
        $password = config('services.first_central.password');

        if (! $username || ! $password) {
            throw new \RuntimeException('First Central username and password are not configured.');
        }

        $response = $this->client()
            ->post('/login', [
                'username' => $username,
                'password' => $password,
            ])
            ->throw()
            ->json();

        $ticket = $this->extractDataTicket($response);

        if (! $ticket) {
            throw new \RuntimeException('First Central login response did not contain a DataTicket.');
        }

        Cache::put(self::DATA_TICKET_CACHE_KEY, $ticket, now()->addHours(4));

        return $ticket;
    }

    public function getDataTicket(): string
    {
        return Cache::remember(self::DATA_TICKET_CACHE_KEY, now()->addHours(4), fn () => $this->login());
    }

    /** @throws RequestException */
    public function consumerMatch(array $data): array
    {
        $payload = array_merge($data, [
            'DataTicket' => $this->getDataTicket(),
        ]);

        return $this->client()
            ->post('/ConnectConsumerMatch', $payload)
            ->throw()
            ->json();
    }
}
