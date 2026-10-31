<?php

namespace Tests\Unit;

use App\Services\ClaimifyWalletService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaimifyWalletServiceTest extends TestCase
{
    public function test_it_sends_name_inquiry_using_claimify_form_fields(): void
    {
        config([
            'services.claimify_wallet.base_url' => 'https://claimify-api.example.test/api/v1',
            'services.claimify_wallet.email' => 'admin@example.test',
            'services.claimify_wallet.password' => 'secret',
        ]);

        Http::fake([
            'claimify-api.example.test/api/v1/auth/login' => Http::response([
                'data' => ['access_token' => 'test-token'],
            ]),
            'claimify-api.example.test/*' => Http::response(['accountName' => 'Jane Doe']),
        ]);

        $response = app(ClaimifyWalletService::class)->nameInquiry('100004', '8024035326');

        $this->assertSame('Jane Doe', $response['accountName']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://claimify-api.example.test/api/v1/auth/wallet/name-inquiry'
                && $request->method() === 'POST'
                && str_contains($request->body(), 'bankCode')
                && str_contains($request->body(), '100004')
                && str_contains($request->body(), 'accountNumber')
                && str_contains($request->body(), '8024035326')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }
}
