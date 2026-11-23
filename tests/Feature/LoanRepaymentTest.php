<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanPayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Tests\TestCase;

class LoanRepaymentTest extends TestCase
{
    public function test_user_can_pay_the_full_remaining_loan_from_wallet()
    {
        $user = User::create([
            'fullname' => 'Full Payoff User',
            'email' => 'full-payoff@example.com',
            'phone_number' => '08087654320',
            'password' => 'secret1234',
            'role' => 'user',
        ]);
        $user->wallet()->create([
            'balance' => 21500,
            'currency' => 'NGN',
        ]);
        $user->setTransactionPin('0811');

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 20000,
            'interest_rate' => 7.5,
            'total_interest' => 1500,
            'total_repayable' => 21500,
            'monthly_installment' => 3583.33,
            'term_months' => 6,
            'status' => 'active',
            'application_reason' => 'Full payoff test',
        ]);

        $principalPerInstallment = round(20000 / 6, 2);
        $interestPerInstallment = round(1500 / 6, 2);

        for ($number = 1; $number <= 6; $number++) {
            $principal = $number === 6
                ? round(20000 - $principalPerInstallment * 5, 2)
                : $principalPerInstallment;
            $interest = $number === 6
                ? round(1500 - $interestPerInstallment * 5, 2)
                : $interestPerInstallment;

            RepaymentSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => now()->addMonths($number)->toDateString(),
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'penalty_amount' => 0,
                'total_due' => round($principal + $interest, 2),
                'amount_paid' => 0,
                'balance_due' => round($principal + $interest, 2),
                'status' => 'pending',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/user/loans/{$loan->id}/payments", [
                'amount_paid' => '21500',
                'pin' => '0811',
                'payment_reference' => '0987654365432345678',
                'payment_method' => 'wallet',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Loan paid successfully.');

        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertSame(0, $loan->repaymentSchedules()->where('balance_due', '>', 0)->count());
        $this->assertSame(6, $loan->repaymentSchedules()->where('status', 'paid')->count());
    }

    public function test_user_with_partial_installment_must_clear_it_before_paying_more_or_pay_off_loan()
    {
        $user = User::create([
            'fullname' => 'Partial Installment User',
            'email' => 'partial-installment@example.com',
            'phone_number' => '08087654323',
            'password' => 'secret1234',
            'role' => 'user',
        ]);
        $user->wallet()->create([
            'balance' => 5000,
            'currency' => 'NGN',
        ]);
        $user->setTransactionPin('1234');

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 4000,
            'interest_rate' => 12.5,
            'total_interest' => 500,
            'total_repayable' => 4500,
            'monthly_installment' => 1500,
            'term_months' => 3,
            'status' => 'active',
            'application_reason' => 'Partial installment test',
        ]);

        for ($number = 1; $number <= 3; $number++) {
            RepaymentSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => now()->addMonths($number)->toDateString(),
                'principal_amount' => 4000 / 3,
                'interest_amount' => 500 / 3,
                'penalty_amount' => 0,
                'total_due' => 1500,
                'amount_paid' => 0,
                'balance_due' => 1500,
                'status' => 'pending',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/user/loans/{$loan->id}/payments", [
                'amount_paid' => 1000,
                'pin' => '1234',
            ])
            ->assertOk();

        $this->postJson("/api/user/loans/{$loan->id}/payments", [
            'amount_paid' => 2000,
            'pin' => '1234',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Please clear the outstanding installment balance of NGN 500.00 first, or pay the full remaining loan balance of NGN 3500.00 at once.'
            );
    }

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
        $user->setTransactionPin('1234');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/loans/{$loan->id}/disburse")
            ->assertStatus(200);

        $user->wallet->credit(11000.00, 'Test deposit', 'deposit-001');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/user/loans/{$loan->id}/payments", [
                'amount_paid' => 11000.00,
                'pin' => '1234',
                'payment_method' => 'wallet',
                'payment_reference' => 'repay-001',
            ]);

        $response->assertStatus(200);

        $payment = LoanPayment::where('loan_id', $loan->id)->first();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('completed', $payment->loan->fresh()->status);
    }

    public function test_user_loan_list_calculates_remaining_balance_from_schedules()
    {
        $user = User::create([
            'fullname' => 'Loan Balance User',
            'email' => 'balance@example.com',
            'phone_number' => '08087654322',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 5000.00,
            'interest_rate' => 5.00,
            'total_interest' => 250.00,
            'total_repayable' => 5250.00,
            'monthly_installment' => 1312.50,
            'term_months' => 4,
            'status' => 'active',
            'application_reason' => 'Loan balance calculation',
        ]);

        RepaymentSchedule::create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'principal_amount' => 1250.00,
            'interest_amount' => 62.50,
            'total_due' => 1312.50,
            'amount_paid' => 1312.50,
            'balance_due' => 0.00,
            'status' => 'paid',
        ]);

        RepaymentSchedule::create([
            'loan_id' => $loan->id,
            'installment_number' => 2,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 1250.00,
            'interest_amount' => 62.50,
            'total_due' => 1312.50,
            'amount_paid' => 1062.50,
            'balance_due' => 0.00,
            'status' => 'partial',
        ]);

        LoanPayment::create([
            'loan_id' => $loan->id,
            'repayment_schedule_id' => $loan->repaymentSchedules()->where('installment_number', 2)->value('id'),
            'user_id' => $user->id,
            'due_date' => now()->addMonth()->toDateString(),
            'amount_due' => 1062.50,
            'amount_paid' => 1062.50,
            'paid_at' => now(),
            'status' => 'partial',
            'payment_reference' => 'partial-payment',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/loans')
            ->assertOk()
            ->assertJsonPath('data.0.balance_due', '250.00')
            ->assertJsonFragment([
                'installment_number' => 2,
                'balance_due' => '250.00',
            ])
            ->assertJsonFragment([
                'payment_reference' => 'partial-payment',
                'amount_due' => '1062.50',
                'installment_total_due' => '1312.50',
                'balance_due' => '250.00',
            ]);

        $this->getJson("/api/user/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.balance_due', '250.00')
            ->assertJsonFragment([
                'payment_reference' => 'partial-payment',
                'amount_due' => '1062.50',
                'installment_total_due' => '1312.50',
                'balance_due' => '250.00',
            ]);

        $this->getJson('/api/admin/loan-payments')
            ->assertOk()
            ->assertJsonFragment([
                'payment_reference' => 'partial-payment',
                'amount_due' => '1062.50',
                'amount_paid' => '1062.50',
                'installment_total_due' => '1312.50',
                'balance_due' => '250.00',
            ]);

        $this->getJson("/api/admin/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.balance_due', '250.00')
            ->assertJsonFragment([
                'payment_reference' => 'partial-payment',
                'installment_total_due' => '1312.50',
                'balance_due' => '250.00',
            ]);
    }
}
