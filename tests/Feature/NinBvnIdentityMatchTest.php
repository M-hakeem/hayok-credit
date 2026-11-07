<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClaimifyWalletService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NinBvnIdentityMatchTest extends TestCase
{
    public function test_nin_can_be_initiated_before_bvn_verification(): void
    {
        $user = User::factory()->create();

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('initiateVerification')
            ->once()
            ->with('NIN', '12345678901')
            ->andReturn(['status' => 'success']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/initiate', [
                'type' => 'NIN',
                'number' => '12345678901',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame([
            'type' => 'NIN',
            'number' => '12345678901',
        ], Cache::get('claimify:pending-identity:'.$user->id));
    }

    public function test_nin_verified_first_populates_user_profile(): void
    {
        $user = User::factory()->create([
            'fullname' => null,
            'dob' => null,
            'gender' => null,
            'email' => null,
        ]);
        $this->setPendingNin($user);

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'Jane',
                'middleName' => 'Amaka',
                'lastName' => 'Doe',
                'fullName' => 'Jane Amaka Doe',
                'dateOfBirth' => '11-10-1996',
                'gender' => 'f',
                'email' => 'JANE.DOE@example.test',
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'nin-verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertSame('Jane Amaka Doe', $user->fullname);
        $this->assertSame('1996-10-11', $user->dob);
        $this->assertSame('female', $user->gender);
        $this->assertSame('jane.doe@example.test', $user->email);
        $this->assertSame('Jane', $user->nin_first_name);
        $this->assertSame('Amaka', $user->nin_middle_name);
        $this->assertSame('Doe', $user->nin_last_name);
        $this->assertSame('1996-10-11', $user->nin_date_of_birth);
        $this->assertSame('female', $user->nin_gender);
        $this->assertNotNull($user->nin_verified_at);
        $this->assertSame('tier_1', $user->account_level);
    }

    public function test_verification_is_rejected_when_provider_biodata_is_incomplete(): void
    {
        $user = User::factory()->create();
        $this->setPendingNin($user);

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'dateOfBirth' => null,
                'gender' => null,
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'nin-verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The identity provider did not return the required name, date of birth, and gender details.');

        $user->refresh();
        $this->assertNull($user->nin);
        $this->assertNull($user->nin_verified_at);
    }

    public function test_matching_nin_biodata_is_accepted(): void
    {
        $user = $this->userWithVerifiedBvn();
        $this->setPendingNin($user);

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'jane',
                'middleName' => 'AMAKA',
                'lastName' => 'DOE',
                'dateOfBirth' => '11-10-1996',
                'gender' => 'f',
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'nin-verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertSame('12345678901', $user->nin);
        $this->assertNotNull($user->nin_verified_at);
    }

    public function test_middle_name_is_not_required_when_the_nin_response_omits_it(): void
    {
        $user = $this->userWithVerifiedBvn();
        $this->setPendingNin($user);

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'Jane',
                'middleName' => '',
                'lastName' => 'Doe',
                'dateOfBirth' => '1996-10-11',
                'gender' => 'female',
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'nin-verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertOk();
    }

    #[DataProvider('mismatchedBiodata')]
    public function test_mismatching_nin_biodata_is_rejected(array $overrides): void
    {
        $user = $this->userWithVerifiedBvn();
        $this->setPendingNin($user);

        $providerResponse = array_merge([
            'firstName' => 'Jane',
            'middleName' => 'Amaka',
            'lastName' => 'Doe',
            'dateOfBirth' => '1996-10-11',
            'gender' => 'female',
        ], $overrides);

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse($providerResponse));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'nin-verification-id',
                'type' => 'NIN',
                'otp' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'NIN details do not match your previously verified BVN.');

        $user->refresh();
        $this->assertNull($user->nin);
        $this->assertNull($user->nin_verified_at);
        $this->assertFalse(Cache::has('claimify:pending-identity:'.$user->id));
    }

    public function test_matching_bvn_after_nin_verification_is_accepted(): void
    {
        $user = User::factory()->create([
            'nin' => '12345678901',
            'nin_verified_at' => now(),
            'nin_first_name' => 'Jane',
            'nin_middle_name' => 'Amaka',
            'nin_last_name' => 'Doe',
            'nin_date_of_birth' => '1996-10-11',
            'nin_gender' => 'female',
        ]);
        Cache::put('claimify:pending-identity:'.$user->id, [
            'type' => 'BVN',
            'number' => '10987654321',
        ], now()->addMinutes(30));

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'JANE',
                'middleName' => 'AMAKA',
                'lastName' => 'DOE',
                'dateOfBirth' => '1996-10-11',
                'gender' => 'Female',
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'bvn-verification-id',
                'type' => 'BVN',
                'otp' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertSame('10987654321', $user->bvn);
        $this->assertNotNull($user->bvn_verified_at);
        $this->assertSame('tier_2', $user->account_level);
    }

    public function test_mismatching_bvn_after_nin_verification_is_rejected(): void
    {
        $user = User::factory()->create([
            'nin' => '12345678901',
            'nin_verified_at' => now(),
            'nin_first_name' => 'Jane',
            'nin_middle_name' => 'Amaka',
            'nin_last_name' => 'Doe',
            'nin_date_of_birth' => '1996-10-11',
            'nin_gender' => 'female',
        ]);
        Cache::put('claimify:pending-identity:'.$user->id, [
            'type' => 'BVN',
            'number' => '10987654321',
        ], now()->addMinutes(30));

        $this->mock(ClaimifyWalletService::class)
            ->shouldReceive('validateVerification')
            ->once()
            ->andReturn($this->verificationResponse([
                'firstName' => 'Jane',
                'middleName' => 'Amaka',
                'lastName' => 'Other',
                'dateOfBirth' => '1996-10-11',
                'gender' => 'Female',
            ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/wallet/verification/validate', [
                'identity_id' => 'bvn-verification-id',
                'type' => 'BVN',
                'otp' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'BVN details do not match your previously verified NIN.');

        $user->refresh();
        $this->assertNull($user->bvn);
        $this->assertNull($user->bvn_verified_at);
        $this->assertFalse(Cache::has('claimify:pending-identity:'.$user->id));
    }

    public static function mismatchedBiodata(): array
    {
        return [
            'first name' => [['firstName' => 'Janet']],
            'middle name' => [['middleName' => 'Amina']],
            'last name' => [['lastName' => 'Smith']],
            'date of birth' => [['dateOfBirth' => '1996-10-12']],
            'gender' => [['gender' => 'other']],
        ];
    }

    private function userWithVerifiedBvn(): User
    {
        return User::factory()->create([
            'bvn' => '10987654321',
            'bvn_verified_at' => now(),
            'bvn_first_name' => 'Jane',
            'bvn_middle_name' => 'Amaka',
            'bvn_last_name' => 'Doe',
            'bvn_date_of_birth' => '1996-10-11',
            'bvn_gender' => 'female',
        ]);
    }

    private function setPendingNin(User $user): void
    {
        Cache::put('claimify:pending-identity:'.$user->id, [
            'type' => 'NIN',
            'number' => '12345678901',
        ], now()->addMinutes(30));
    }

    private function verificationResponse(array $providerResponse): array
    {
        return [
            'data' => [
                'message' => 'success',
                'token' => [
                    'statusCode' => 200,
                    'message' => 'record fetched successfully',
                    'data' => [
                        'providerResponse' => $providerResponse,
                    ],
                ],
            ],
        ];
    }
}
