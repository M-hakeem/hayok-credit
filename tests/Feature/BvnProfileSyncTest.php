<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClaimifyWalletService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BvnProfileSyncTest extends TestCase
{
    public function test_successful_bvn_verification_updates_profile_details_but_not_profile_image(): void
    {
        $user = User::factory()->create([
            'fullname' => 'Old Name',
            'dob' => '1990-01-01',
            'gender' => 'other',
            'email' => 'old@example.test',
            'profile_image' => 'profile_images/current.jpg',
        ]);
        Cache::put('claimify:pending-identity:'.$user->id, [
            'type' => 'BVN',
            'number' => '12345678901',
        ], now()->addMinutes(30));

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->with('verification-id', 'BVN', '123456')
            ->andReturn([
                'data' => [
                    'message' => 'success',
                    'token' => [
                        'statusCode' => 200,
                        'message' => 'record fetched successfully',
                        'data' => [
                            'providerResponse' => [
                                'firstName' => 'Jane',
                                'middleName' => 'Amaka',
                                'lastName' => 'Doe',
                                'gender' => 'Female',
                                'dateOfBirth' => '1996-10-11',
                                'email' => 'JANE.DOE@example.test',
                                'base64Image' => 'ignored-photo-data',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'verification-id',
                'type' => 'BVN',
                'otp' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.token.data.providerResponse.firstName', 'Jane');

        $user->refresh();
        $this->assertSame('Jane Amaka Doe', $user->fullname);
        $this->assertSame('1996-10-11', $user->dob);
        $this->assertSame('female', $user->gender);
        $this->assertSame('jane.doe@example.test', $user->email);
        $this->assertSame('profile_images/current.jpg', $user->profile_image);
        $this->assertSame('12345678901', $user->bvn);
        $this->assertNotNull($user->bvn_verified_at);
        $this->assertSame('Jane', $user->bvn_first_name);
        $this->assertSame('Amaka', $user->bvn_middle_name);
        $this->assertSame('Doe', $user->bvn_last_name);
        $this->assertSame('1996-10-11', $user->bvn_date_of_birth);
        $this->assertSame('female', $user->bvn_gender);
        $this->assertArrayNotHasKey('bvn_first_name', $user->toArray());
        $this->assertSame('tier_1', $user->account_level);
    }

    public function test_matching_nin_verification_preserves_verified_bvn_profile_details(): void
    {
        $user = User::factory()->create([
            'fullname' => 'Existing Name',
            'dob' => '1990-01-01',
            'gender' => 'other',
            'email' => 'existing@example.test',
            'bvn_verified_at' => now(),
            'bvn_first_name' => 'Existing',
            'bvn_middle_name' => null,
            'bvn_last_name' => 'Name',
            'bvn_date_of_birth' => '1990-01-01',
            'bvn_gender' => 'other',
        ]);
        Cache::put('claimify:pending-identity:'.$user->id, [
            'type' => 'NIN',
            'number' => '12345678901',
        ], now()->addMinutes(30));

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn([
                'data' => [
                    'message' => 'success',
                    'token' => [
                        'statusCode' => 200,
                        'message' => 'record fetched successfully',
                        'data' => [
                            'providerResponse' => [
                                'firstName' => 'Existing',
                                'lastName' => 'Name',
                                'gender' => 'Other',
                                'dateOfBirth' => '1990-01-01',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('Existing Name', $user->fullname);
        $this->assertSame('1990-01-01', $user->dob);
        $this->assertSame('other', $user->gender);
        $this->assertSame('existing@example.test', $user->email);
        $this->assertSame('Existing', $user->nin_first_name);
        $this->assertSame('Name', $user->nin_last_name);
        $this->assertSame('tier_2', $user->account_level);
    }
}
