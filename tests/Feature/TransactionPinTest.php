<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransactionPinTest extends TestCase
{
    public function test_user_can_set_and_change_a_four_digit_transaction_pin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/pin')
            ->assertOk()
            ->assertJsonPath('data.has_pin', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin', [
                'pin' => '0123',
                'pin_confirmation' => '0123',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_pin', true)
            ->assertJsonMissingPath('data.transaction_pin_hash');

        $user->refresh();
        $this->assertTrue(Hash::check('0123', $user->transaction_pin_hash));
        $this->assertNotSame('0123', $user->transaction_pin_hash);
        $this->assertArrayNotHasKey('transaction_pin_hash', $user->toArray());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin', [
                'current_pin' => '0123',
                'pin' => '9876',
                'pin_confirmation' => '9876',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('9876', $user->fresh()->transaction_pin_hash));
    }

    public function test_user_must_enter_the_current_pin_to_change_it(): void
    {
        $user = User::factory()->create();
        $user->setTransactionPin('1234');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin', [
                'pin' => '9876',
                'pin_confirmation' => '9876',
            ])
            ->assertUnprocessable();

        $this->assertTrue(Hash::check('1234', $user->fresh()->transaction_pin_hash));
    }

    public function test_user_can_reset_their_pin_with_an_otp_sent_to_the_verified_number(): void
    {
        config([
            'services.termii.api_key' => 'test-api-key',
            'services.termii.sender_id' => 'HayokCredit',
            'services.termii.type' => 'plain',
            'services.termii.channel' => 'generic',
            'services.termii.url' => 'https://api.ng.termii.com/api/sms/send',
        ]);
        Http::fake([
            '*' => Http::response(['code' => 'ok', 'message' => 'Successfully Sent'], 200),
        ]);

        $user = User::factory()->create([
            'phone_number' => '+2347061234567',
            'phone_verified_at' => now(),
        ]);
        $user->setTransactionPin('1234');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin/reset-otp')
            ->assertAccepted();

        $sms = Http::recorded()->first()[0]['sms'];
        preg_match('/(\d{6})$/', $sms, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin/reset', [
                'otp' => $matches[1],
                'pin' => '5678',
                'pin_confirmation' => '5678',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_pin', true);

        $this->assertTrue(Hash::check('5678', $user->fresh()->transaction_pin_hash));
        $this->assertDatabaseHas('phone_verifications', [
            'phone_number' => '+2347061234567',
            'purpose' => 'transaction_pin_reset',
            'otp_hash' => null,
            'delivery_status' => 'consumed',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/pin/reset', [
                'otp' => $matches[1],
                'pin' => '2468',
                'pin_confirmation' => '2468',
            ])
            ->assertUnprocessable();
        $this->assertTrue(Hash::check('5678', $user->fresh()->transaction_pin_hash));
    }

    public function test_withdrawals_and_repayments_require_a_four_digit_pin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', ['amount' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans/1/payments', ['amount_paid' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_withdrawal_is_rejected_when_the_transaction_pin_is_wrong(): void
    {
        $user = User::factory()->create();
        $user->setTransactionPin('1234');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/withdraw', [
                'amount' => 1000,
                'pin' => '9999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin']);

        $this->assertDatabaseCount('withdrawals', 0);
    }
}
