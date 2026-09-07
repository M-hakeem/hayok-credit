<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTest extends TestCase
{
    public function test_authenticated_user_can_view_only_their_own_wallet(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Wallet::create([
            'user_id' => $user->id,
            'balance' => 1250.50,
            'status' => 'active',
            'currency' => 'NGN',
        ]);
        Wallet::create([
            'user_id' => $otherUser->id,
            'balance' => 9999.99,
            'status' => 'active',
            'currency' => 'NGN',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/wallet')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.wallet.user_id', $user->id)
            ->assertJsonPath('data.wallet.balance', '1250.50')
            ->assertJsonMissing(['balance' => '9999.99']);
    }

    public function test_user_without_a_wallet_receives_not_found_response(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/wallet')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_wallet_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user/wallet')->assertUnauthorized();
    }

    public function test_admin_can_view_a_users_wallet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'balance' => 500.00,
            'status' => 'active',
            'currency' => 'NGN',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$user->id}/wallet")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.wallet.user_id', $user->id)
            ->assertJsonPath('data.wallet.balance', '500.00');
    }

    public function test_non_admin_cannot_view_a_users_wallet(): void
    {
        $user = User::factory()->create();
        $targetUser = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/admin/users/{$targetUser->id}/wallet")
            ->assertForbidden();
    }
}