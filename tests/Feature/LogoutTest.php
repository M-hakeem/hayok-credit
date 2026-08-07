<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    public function test_authenticated_user_can_logout_and_revoke_only_the_current_token(): void
    {
        $user = User::create([
            'phone_number' => '+2348012345678',
            'password' => 'password',
        ]);
        $currentToken = $user->createToken('auth_token');
        $otherToken = $user->createToken('auth_token');

        $response = $this
            ->withToken($currentToken->plainTextToken)
            ->postJson('/api/auth/logout');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'message' => 'Logout successful',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
