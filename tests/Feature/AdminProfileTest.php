<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    public function test_admin_can_view_and_update_their_profile(): void
    {
        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phone_number' => '08012345671',
            'password' => 'current-password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/profile')
            ->assertOk()
            ->assertJsonPath('data.fullname', 'Admin User')
            ->assertJsonPath('data.email', 'admin@example.com')
            ->assertJsonPath('data.phone_number', '08012345671');

        $this->putJson('/api/admin/profile', [
            'fullname' => 'Updated Admin',
            'email' => 'updated@example.com',
            'phone_number' => '08012345672',
        ])
            ->assertOk()
            ->assertJsonPath('data.fullname', 'Updated Admin')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.phone_number', '08012345672');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'fullname' => 'Updated Admin',
            'email' => 'updated@example.com',
            'phone_number' => '08012345672',
        ]);
    }

    public function test_admin_can_change_password_only_after_providing_current_password(): void
    {
        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phone_number' => '08012345671',
            'password' => 'current-password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/profile', [
                'fullname' => 'Admin User',
                'email' => 'admin@example.com',
                'phone_number' => '08012345671',
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->putJson('/api/admin/profile', [
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phone_number' => '08012345671',
            'current_password' => 'current-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $admin->fresh()->password));
    }
}
