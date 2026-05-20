<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

class LoanDisbursementScheduleTest extends TestCase
{
    public function test_repayment_schedule_is_generated_at_disbursement()
    {
        $user = User::create([
            'fullname' => 'Test User',
            'email' => 'user@example.com',
            'phone_number' => '08012345678',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Test User',
            'bank_code' => '123',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 30000.00,
            'interest_rate' => 10.00,
            'total_interest' => 3000.00,
            'total_repayable' => 33000.00,
            'monthly_installment' => 11000.00,
            'term_months' => 3,
            'status' => 'approved',
            'approved_at' => now(),
            'application_reason' => 'Test loan',
        ]);

        $disbursement = LoanDisbursement::create([
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'amount' => 30000.00,
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
            'bank_code' => $user->bank_code,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/loan-disbursements/{$disbursement->id}/disburse", [
                'transaction_reference' => 'txn-0001',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('loan_disbursements', [
            'id' => $disbursement->id,
            'status' => 'disbursed',
        ]);

        $loan->refresh();

        $this->assertSame('active', $loan->status);
        $this->assertCount(3, $loan->repaymentSchedules);

        $expectedDueDate = Carbon::parse($loan->disbursement->disbursed_at)->addMonths(1)->toDateString();
        $this->assertSame($expectedDueDate, $loan->repaymentSchedules()->orderBy('due_date')->first()->due_date->toDateString());
    }
}
