<?php

namespace Tests\Unit;

use App\Services\FirstCentralService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirstCentralServiceTest extends TestCase
{
    public function test_it_resolves_bank_account_using_first_central_api(): void
    {
        config([
            'services.first_central.base_url' => 'https://firstcentral.example.test/api/v1',
            'services.first_central.api_key' => 'first-central-key',
        ]);

        Http::fake([
            'firstcentral.example.test/*' => Http::response(['account_name' => 'Jane Doe']),
        ]);

        $response = app(FirstCentralService::class)->resolveAccount('123456', '1234567890');

        $this->assertSame('Jane Doe', $response['account_name']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://firstcentral.example.test/api/v1/bank/account/resolve'
                && $request->method() === 'POST'
                && $request->hasHeader('X-API-KEY', 'first-central-key')
                && str_contains($request->body(), 'bank_code')
                && str_contains($request->body(), 'account_number');
        });
    }
}
