<?php

namespace Tests\Unit;

use App\Services\FirstCentralService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirstCentralServiceTest extends TestCase
{
    public function test_it_generates_a_data_ticket_from_username_and_password_and_uses_it_in_consumer_match(): void
    {
        config([
            'services.first_central.base_url' => 'https://uat.firstcentralcreditbureau.com/firstcentralrestv2',
            'services.first_central.username' => 'demo',
            'services.first_central.password' => 'demo@123',
            'services.first_central.token' => null,
            'services.first_central.api_key' => null,
            'services.first_central.timeout' => 20,
        ]);

        Cache::forget('first_central.data_ticket');

        Http::fake([
            'https://uat.firstcentralcreditbureau.com/firstcentralrestv2/login' => Http::response([
                ['DataTicket' => 'generated-ticket'],
            ]),
            'https://uat.firstcentralcreditbureau.com/firstcentralrestv2/ConnectConsumerMatch' => Http::response([
                'status' => 'success',
            ]),
        ]);

        $response = app(FirstCentralService::class)->consumerMatch([
            'EnquiryReason' => 'Test enquiry',
            'ConsumerName' => 'Jane Doe',
            'DateOfBirth' => '1990-01-01',
            'Identification' => '12345678901',
            'Accountno' => '1234567890',
            'ProductID' => 45,
        ]);

        $this->assertSame(['status' => 'success'], $response);
        Http::assertSentCount(2);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://uat.firstcentralcreditbureau.com/firstcentralrestv2/login'
                && $request->method() === 'POST'
                && $request->data()['username'] === 'demo'
                && $request->data()['password'] === 'demo@123';
        });

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://uat.firstcentralcreditbureau.com/firstcentralrestv2/ConnectConsumerMatch'
                && $request->method() === 'POST'
                && data_get($request->data(), 'DataTicket') === 'generated-ticket'
                && data_get($request->data(), 'ConsumerName') === 'Jane Doe'
                && data_get($request->data(), 'ProductID') === 45;
        });
    }
}
