<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletDeposit;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletDepositTest extends TestCase
{
    private function user(): User
    {
        $user = User::create([
            'fullname' => 'Wallet Deposit User',
            'email' => 'wallet-deposit@example.com',
            'phone_number' => '08000000002',
            'password' => 'secret1234',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'balance' => 500,
            'currency' => 'NGN',
        ]);

        return $user;
    }

    public function test_deposit_initialization_returns_paystack_checkout_without_crediting_wallet(): void
    {
        $user = $this->user();
        Http::fake(function ($request) {
            return Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.example.test',
                    'access_code' => 'access',
                    'reference' => $request['reference'],
                ],
            ]);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/deposit', ['amount' => 2500, 'description' => 'Top-up']);

        $response->assertOk()
            ->assertJsonPath('data.transaction.authorization_url', 'https://checkout.example.test')
            ->assertJsonPath('data.amount', '2500.00');
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 500]);
        $this->assertDatabaseHas('wallet_deposits', [
            'user_id' => $user->id,
            'amount' => 2500,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertSent(function ($request) use ($user) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request['email'] === $user->email
                && $request['amount'] === 250000
                && $request['currency'] === 'NGN'
                && $request['metadata']['purpose'] === 'wallet_deposit'
                && $request['metadata']['amount_minor'] === 250000;
        });
    }

    public function test_only_a_verified_successful_payment_credits_wallet_and_repeated_verification_is_idempotent(): void
    {
        $user = $this->user();
        $reference = 'wallet-deposit-'.$user->id.'-test';
        $deposit = WalletDeposit::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet->id,
            'reference' => $reference,
            'amount' => 2500,
            'description' => 'Top-up',
        ]);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 42,
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'channel' => 'card',
                    'customer' => ['email' => $user->email],
                    'metadata' => [
                        'purpose' => 'wallet_deposit',
                        'user_id' => $user->id,
                        'wallet_deposit_id' => $deposit->id,
                        'amount_minor' => 250000,
                    ],
                ],
            ]),
        ]);

        $url = '/api/user/wallet/deposit/verify/'.$reference;
        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('data.wallet.balance', '3000.00');
        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('data.wallet.balance', '3000.00');

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 3000]);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallet_deposits', [
            'id' => $deposit->id,
            'status' => 'paid',
        ]);
        $this->assertSame(1, WalletTransaction::where('reference', $reference)->count());
    }

    public function test_failed_paystack_verification_does_not_credit_wallet(): void
    {
        $user = $this->user();
        $reference = 'wallet-deposit-'.$user->id.'-failed';
        WalletDeposit::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet->id,
            'reference' => $reference,
            'amount' => 2500,
        ]);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['reference' => $reference, 'status' => 'failed'],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/wallet/deposit/verify/'.$reference)
            ->assertUnprocessable();

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 500]);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_signed_success_webhook_credits_the_wallet(): void
    {
        $user = $this->user();
        $reference = 'wallet-deposit-'.$user->id.'-webhook';
        $deposit = WalletDeposit::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet->id,
            'reference' => $reference,
            'amount' => 2500,
        ]);
        config(['paystack.secret_key' => 'test-secret']);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 43,
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'customer' => ['email' => $user->email],
                    'metadata' => [
                        'purpose' => 'wallet_deposit',
                        'user_id' => $user->id,
                        'wallet_deposit_id' => $deposit->id,
                        'amount_minor' => 250000,
                    ],
                ],
            ]),
        ]);
        $payload = ['event' => 'charge.success', 'data' => ['reference' => $reference]];
        $signature = hash_hmac('sha512', json_encode($payload), 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson('/api/paystack/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 3000]);
        $this->assertDatabaseHas('wallet_deposits', ['id' => $deposit->id, 'status' => 'paid']);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }
}
