<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileImageTest extends TestCase
{
    public function test_user_list_includes_the_full_bank_account_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'bank_account_number' => '0123456789',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/user');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.bank_account_number', '0123456789');
    }

    public function test_user_can_upload_a_profile_image_during_profile_update(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'fullname' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+2348012345678',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/user/update-profile', [
            'fullname' => 'Jane Smith',
            'profile_image' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.fullname', 'Jane Smith')
            ->assertJsonPath('user.profile_image', fn ($value) => ! empty($value));

        Storage::disk('public')->assertExists($user->fresh()->profile_image);
    }
}
