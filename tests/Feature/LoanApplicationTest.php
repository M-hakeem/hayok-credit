<?php

namespace Tests\Feature;

use App\Models\LoanInterestSetting;
use App\Models\User;
use Tests\TestCase;

class LoanApplicationTest extends TestCase
{
    public function test_user_with_wallet_can_apply_without_connecting_a_bank_account(): void
    {
        $user = User::create([
            'fullname' => 'Wallet Borrower',
            'email' => 'wallet-borrower@example.com',
            'phone_number' => '08012345678',
            'password' => 'secret1234',
            'role' => 'user',
        ]);
        $user->wallet()->create(['balance' => 0, 'currency' => 'NGN']);
        LoanInterestSetting::create(['interest_rate' => 10, 'tenure_months' => 6, 'active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 10000,
                'term_months' => 6,
                'application_reason' => 'Personal expenses',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'success');
    }

    public function test_user_without_wallet_cannot_apply_for_a_loan(): void
    {
        $user = User::create([
            'fullname' => 'No Wallet Borrower',
            'email' => 'no-wallet-borrower@example.com',
            'phone_number' => '08012345679',
            'password' => 'secret1234',
            'role' => 'user',
        ]);
        LoanInterestSetting::create(['interest_rate' => 10, 'tenure_months' => 6, 'active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/loans', [
                'amount_requested' => 10000,
                'term_months' => 6,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Please create a wallet before applying for a loan.');
    }
}
