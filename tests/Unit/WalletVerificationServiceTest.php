<?php

namespace Tests\Unit;

use App\Services\WalletVerificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletVerificationServiceTest extends TestCase
{
    public function test_it_sends_name_inquiry_using_claimify_form_fields(): void
    {
        config([
            'services.wallet_verification.base_url' => 'https://claimify-api.example.test/api/v1',
            'services.wallet_verification.token' => 'test-token',
        ]);

        Http::fake([
            'claimify-api.example.test/*' => Http::response(['accountName' => 'Jane Doe']),
        ]);

        $response = app(WalletVerificationService::class)->nameInquiry('100004', '8024035326');

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
