<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

class IdentityVerificationStatusTest extends TestCase
{
    public function test_user_can_view_identity_added_and_verification_statuses(): void
    {
        $verifiedAt = Carbon::parse('2026-09-25 17:00:00');
        $user = User::factory()->create([
            'nin' => '12345678901',
            'nin_verified_at' => $verifiedAt,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/identity-verification-status')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.nin.added', true)
            ->assertJsonPath('data.nin.verified', true)
            ->assertJsonPath('data.nin.verified_at', $verifiedAt->toISOString())
            ->assertJsonPath('data.bvn.added', false)
            ->assertJsonPath('data.bvn.verified', false)
            ->assertJsonPath('data.bvn.verified_at', null)
            ->assertJsonMissing(['12345678901']);
    }

    public function test_identity_verification_status_requires_authentication(): void
    {
        $this->getJson('/api/user/identity-verification-status')->assertUnauthorized();
    }
}
