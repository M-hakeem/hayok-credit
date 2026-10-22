<?php

namespace Tests\Feature;

use App\Models\FirstLoanLimit;
use App\Models\Loan;
use App\Models\LoanInterestSetting;
use App\Models\User;
use Tests\TestCase;

class FirstLoanLimitTest extends TestCase
{
    public function test_admin_can_read_and_update_the_first_loan_limit(): void
    {
        $admin = User::create([
            'fullname' => 'Loan Admin',
            'email' => 'loan-admin@example.com',
            'phone_number' => '08012345670',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/first-loan-limit')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.amount', null);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/first-loan-limit', ['amount' => 10000000])
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/first-loan-limit', ['amount' => 25000])
            ->assertOk()
            ->assertJsonPath('data.amount', '25000.00')
            ->assertJsonPath('data.configured', true);

        $this->assertSame(1, FirstLoanLimit::query()->count());
    }

    public function test_first_time_user_can_read_their_configured_entitlement(): void
    {
        $user = $this->createUser();
        FirstLoanLimit::create(['id' => 1, 'amount' => 25000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/first-loan-limit')
            ->assertOk()
            ->assertJsonPath('data.is_first_time_user', true)
            ->assertJsonPath('data.maximum_amount', '25000.00')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.currency', 'NGN');
    }

    public function test_first_time_user_cannot_request_more_than_the_configured_limit(): void
    {
        $user = $this->createUser();
        FirstLoanLimit::create(['id' => 1, 'amount' => 25000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 25000.01,
                'term_months' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'First-time users can request a maximum of NGN 25,000.00.',
            );
    }

    public function test_first_time_user_can_request_the_configured_limit(): void
    {
        $user = $this->createUser();
        $user->wallet()->create(['balance' => 0, 'currency' => 'NGN']);
        FirstLoanLimit::create(['id' => 1, 'amount' => 25000]);
        LoanInterestSetting::create([
            'interest_rate' => 5,
            'tenure_months' => 2,
            'active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 25000,
                'term_months' => 2,
            ])
            ->assertCreated();
    }

    public function test_user_with_prior_loan_history_is_not_subject_to_the_first_loan_limit(): void
    {
        $user = $this->createUser();
        $user->wallet()->create(['balance' => 0, 'currency' => 'NGN']);
        FirstLoanLimit::create(['id' => 1, 'amount' => 25000]);
        LoanInterestSetting::create([
            'interest_rate' => 5,
            'tenure_months' => 2,
            'active' => true,
        ]);
        Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 10000,
            'term_months' => 2,
            'status' => 'rejected',
        ])->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/first-loan-limit')
            ->assertOk()
            ->assertJsonPath('data.is_first_time_user', false)
            ->assertJsonPath('data.maximum_amount', null);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 50000,
                'term_months' => 2,
            ])
            ->assertCreated();
    }

    public function test_first_time_user_cannot_apply_until_an_admin_configures_a_limit(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 10000,
                'term_months' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The first-loan request limit has not been configured. Please contact the administrator.',
            );
    }

    private function createUser(): User
    {
        return User::create([
            'fullname' => 'First Loan Borrower',
            'email' => 'borrower-'.uniqid().'@example.com',
            'phone_number' => '080'.random_int(10000000, 99999999),
            'password' => 'secret1234',
            'role' => 'user',
        ]);
    }
}
