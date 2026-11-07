<?php

namespace Tests\Feature;

use App\Models\Address;
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
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->post('/api/user/update-profile', [
            'profile_image' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.fullname', 'Jane Doe')
            ->assertJsonPath('user.profile_image', fn ($value) => ! empty($value))
            ->assertJsonPath('user.profile_image_url', fn ($value) => str_starts_with($value, 'http'));

        Storage::disk('public')->assertExists($user->fresh()->profile_image);
    }

    public function test_profile_update_rejects_a_request_without_a_profile_image(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/user/update-profile', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_profile_update_rejects_personal_information_changes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'fullname' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/user/update-profile', [
            'fullname' => 'Different Name',
            'email' => 'different@example.com',
            'gender' => 'other',
            'dob' => '1990-01-01',
            'profile_image' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fullname', 'email', 'gender', 'dob']);

        $this->assertSame('Jane Doe', $user->fresh()->fullname);
        $this->assertSame('jane@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->profile_image);
    }

    public function test_address_creation_requires_a_utility_bill(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/address', [
            'residential_address' => '12 Example Street, Ikeja',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utility_bill');
    }

    public function test_address_update_requires_a_new_utility_bill(): void
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'residential_address' => '12 Example Street, Ikeja',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'utility_bill_path' => 'utility_bills/current.pdf',
            'verification_status' => 'verified',
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->putJson('/api/address/'.$address->id, [
            'residential_address' => '14 Example Street, Ikeja',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utility_bill');

        $this->assertSame('12 Example Street, Ikeja', $address->fresh()->residential_address);
    }
}
