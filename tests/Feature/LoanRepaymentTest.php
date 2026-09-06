<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanPayment;
use App\Models\User;
use Tests\TestCase;

class LoanRepaymentTest extends TestCase
{
    public function test_user_can_repay_installment_using_wallet_and_loan_closes()
    {
        $user = User::create([
            'fullname' => 'Loan Payer',
            'email' => 'payer@example.com',
            'phone_number' => '08087654321',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '0987654321',
            'bank_account_name' => 'Loan Payer',
            'bank_code' => '321',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 10000.00,
            'interest_rate' => 10.00,
            'total_interest' => 1000.00,
            'total_repayable' => 11000.00,
            'monthly_installment' => 11000.00,
            'term_months' => 1,
            'status' => 'approved',
            'approved_at' => now(),
            'application_reason' => 'Single payment loan',
        ]);

        $disbursement = LoanDisbursement::create([
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'amount' => 10000.00,
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
            'bank_code' => $user->bank_code,
            'status' => 'pending',
        ]);

        $user->wallet()->create([
            'balance' => 0,
            'currency' => 'NGN',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/loans/{$loan->id}/disburse")
            ->assertStatus(200);

        $user->wallet->credit(11000.00, 'Test deposit', 'deposit-001');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/user/loans/{$loan->id}/payments", [
                'amount_paid' => 11000.00,
                'payment_method' => 'wallet',
                'payment_reference' => 'repay-001',
            ]);

        $response->assertStatus(200);

        $payment = LoanPayment::where('loan_id', $loan->id)->first();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('completed', $payment->loan->fresh()->status);
    }
}
