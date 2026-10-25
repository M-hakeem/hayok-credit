<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletWithdrawalTest extends TestCase
{
    private function user(): User
    {
        $user = User::create([
            'fullname' => 'Wallet Withdrawal User',
            'email' => 'wallet-withdrawal@example.com',
            'phone_number' => '08000000003',
            'password' => 'secret1234',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '0123456789',
            'bank_account_name' => 'Wallet Withdrawal User',
            'bank_code' => '001',
            'bank_connected_at' => now(),
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'balance' => 5000,
            'currency' => 'NGN',
        ]);

        return $user;
    }

    private function fakeRecipientAndTransfer(array $transferResponse = ['status' => 'pending']): void
    {
        Http::fake([
            'api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_name' => 'Verified Account Name', 'bank_name' => 'Verified Bank'],
            ]),
            'api.paystack.co/transferrecipient' => Http::response([
                'status' => true,
                'data' => ['recipient_code' => 'RCP_test'],
            ]),
            'api.paystack.co/transfer' => Http::response([
                'status' => true,
                'data' => $transferResponse + ['transfer_code' => 'TRF_test'],
            ]),
        ]);
    }

    public function test_withdrawal_initiates_transfer_and_reserves_balance_until_provider_confirmation(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250, 'description' => 'Cash out']);

        $response->assertStatus(202)
            ->assertJsonPath('data.withdrawal.status', 'processing')
            ->assertJsonPath('data.wallet.balance', '3750.00')
            ->assertJsonMissingPath('data.withdrawal.bank_account_number');
        $withdrawal = Withdrawal::firstOrFail();
        $this->assertSame('RCP_test', $withdrawal->paystack_recipient_code);
        $this->assertSame('Verified Account Name', $withdrawal->bank_account_name);
        $this->assertSame('processing', $withdrawal->status);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference' => $withdrawal->paystack_reference,
            'type' => 'debit',
            'amount' => 1250,
        ]);
        Http::assertSent(function ($request) use ($withdrawal) {
            return $request->url() === 'https://api.paystack.co/transfer'
                && $request['source'] === 'balance'
                && $request['amount'] === 125000
                && $request['recipient'] === 'RCP_test'
                && $request['reference'] === $withdrawal->paystack_reference;
        });
    }

    public function test_successful_transfer_webhook_completes_withdrawal_without_refunding_it(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250])
            ->assertStatus(202);
        $withdrawal = Withdrawal::firstOrFail();
        config(['paystack.secret_key' => 'test-secret']);
        $payload = ['event' => 'transfer.success', 'data' => ['reference' => $withdrawal->paystack_reference]];
        $signature = hash_hmac('sha512', json_encode($payload), 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson('/api/paystack/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id, 'status' => 'completed']);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 3750]);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_failed_transfer_webhook_refunds_wallet_exactly_once(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250])
            ->assertStatus(202);
        $withdrawal = Withdrawal::firstOrFail();
        config(['paystack.secret_key' => 'test-secret']);
        $payload = [
            'event' => 'transfer.failed',
            'data' => ['reference' => $withdrawal->paystack_reference, 'reason' => 'Transfer failed'],
        ];
        $signature = hash_hmac('sha512', json_encode($payload), 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])->postJson('/api/paystack/webhook', $payload)->assertOk();
        $this->withHeaders(['x-paystack-signature' => $signature])->postJson('/api/paystack/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id, 'status' => 'failed']);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 5000]);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference' => 'withdrawal-refund-'.$withdrawal->id,
            'type' => 'credit',
            'amount' => 1250,
        ]);
    }

    public function test_user_can_poll_transfer_status_until_paystack_confirms_it(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250])
            ->assertStatus(202);
        $withdrawal = Withdrawal::firstOrFail();
        Http::fake([
            'api.paystack.co/transfer/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => $withdrawal->paystack_reference,
                    'status' => 'success',
                    'transfer_code' => 'TRF_test',
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/wallet/withdraw/verify/'.$withdrawal->paystack_reference)
            ->assertOk()
            ->assertJsonPath('data.withdrawal.status', 'completed');

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 3750]);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_reversed_successful_transfer_refunds_reserved_wallet_amount(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250])
            ->assertStatus(202);
        $withdrawal = Withdrawal::firstOrFail();
        config(['paystack.secret_key' => 'test-secret']);

        foreach (['transfer.success', 'transfer.reversed'] as $eventName) {
            $payload = ['event' => $eventName, 'data' => ['reference' => $withdrawal->paystack_reference]];
            $signature = hash_hmac('sha512', json_encode($payload), 'test-secret');
            $this->withHeaders(['x-paystack-signature' => $signature])
                ->postJson('/api/paystack/webhook', $payload)
                ->assertOk();
        }

        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id, 'status' => 'failed']);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 5000]);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_user_without_a_validated_bank_account_cannot_withdraw(): void
    {
        $user = $this->user();
        $user->update(['bank_connected_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1250])
            ->assertUnprocessable();

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 5000]);
        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_insufficient_wallet_balance_does_not_create_withdrawal(): void
    {
        $user = $this->user();
        $this->fakeRecipientAndTransfer();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 6000])
            ->assertUnprocessable();

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 5000]);
        $this->assertDatabaseCount('withdrawals', 0);
    }
}
