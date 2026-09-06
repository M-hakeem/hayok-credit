<?php

namespace Tests\Feature;

use App\Models\PaymentAuthorization;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackCardAuthorizationTest extends TestCase
{
    private function user(): User
    {
        return User::create([
            'fullname' => 'Paystack User',
            'email' => 'paystack@example.com',
            'phone_number' => '08000000001',
            'password' => 'secret1234',
        ]);
    }

    public function test_card_initialization_uses_fixed_amount_and_user_email(): void
    {
        $user = $this->user();
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.example.test', 'access_code' => 'access'],
            ]),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/payments/paystack/initialize-card');

        $response->assertOk()->assertJsonPath('data.transaction.authorization_url', 'https://checkout.example.test');
        Http::assertSent(function ($request) use ($user) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request['email'] === $user->email
                && $request['amount'] === 10000
                && $request['metadata']['purpose'] === 'card_authorization';
        });
    }

    public function test_verification_stores_only_reusable_authorization(): void
    {
        $user = $this->user();
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'card-auth-reference',
                    'channel' => 'card',
                    'customer' => ['email' => $user->email, 'customer_code' => 'CUS_test'],
                    'metadata' => ['purpose' => 'card_authorization', 'user_id' => $user->id],
                    'authorization' => [
                        'reusable' => true,
                        'authorization_code' => 'AUTH_test',
                        'signature' => 'sig',
                        'brand' => 'visa',
                        'last4' => '4081',
                        'exp_month' => 12,
                        'exp_year' => 2030,
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/payments/paystack/verify/card-auth-reference')
            ->assertOk();

        $this->assertDatabaseHas('payment_authorizations', [
            'user_id' => $user->id,
            'email' => $user->email,
            'last4' => '4081',
            'reusable' => 1,
            'status' => 'active',
        ]);
        $this->assertSame('AUTH_test', PaymentAuthorization::first()->authorization_code);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        config(['paystack.secret_key' => 'test-secret']);

        $this->postJson('/api/paystack/webhook', ['event' => 'charge.success'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('paystack_webhook_events', 0);
    }

    public function test_duplicate_webhook_is_processed_once(): void
    {
        config(['paystack.secret_key' => 'test-secret']);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'reference' => 'unknown-reference', 'metadata' => []],
            ]),
        ]);
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'unknown-reference']];
        $raw = json_encode($payload);
        $signature = hash_hmac('sha512', $raw, 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])->postJson('/api/paystack/webhook', $payload)->assertOk();
        $this->withHeaders(['x-paystack-signature' => $signature])->postJson('/api/paystack/webhook', $payload)->assertOk();

        $this->assertDatabaseCount('paystack_webhook_events', 1);
    }

    public function test_signed_webhook_with_unsuccessful_transaction_is_acknowledged(): void
    {
        config(['paystack.secret_key' => 'test-secret']);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'reference' => 'dashboard-test-reference'],
            ]),
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'dashboard-test-reference']];
        $raw = json_encode($payload);
        $signature = hash_hmac('sha512', $raw, 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson('/api/paystack/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('paystack_webhook_events', [
            'reference' => 'dashboard-test-reference',
        ]);
    }

}