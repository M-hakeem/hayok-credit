<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
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
            ->postJson("/api/admin/loans/{$loan->id}/disburse");

        $response->assertStatus(200);

        $this->assertDatabaseHas('loan_disbursements', [
            'id' => $disbursement->id,
            'status' => 'disbursed',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'balance' => 30000,
        ]);

        $loan->refresh();

        $this->assertSame('active', $loan->status);
        $this->assertCount(3, $loan->repaymentSchedules);

        $expectedDueDate = Carbon::parse($loan->disbursement->disbursed_at)->addMonths(1)->toDateString();
        $this->assertSame($expectedDueDate, $loan->repaymentSchedules()->orderBy('due_date')->first()->due_date->toDateString());
    }

    public function test_partner_loan_is_disbursed_to_its_bank_account(): void
    {
        $user = User::create([
            'fullname' => 'Partner User',
            'email' => 'partner@example.com',
            'phone_number' => '08012345679',
            'bank_name' => 'Partner Bank',
            'bank_account_number' => '1111111111',
            'bank_account_name' => 'Partner User',
            'bank_code' => '999',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 30000,
            'interest_rate' => 10,
            'total_interest' => 3000,
            'total_repayable' => 33000,
            'monthly_installment' => 11000,
            'term_months' => 3,
            'status' => 'approved',
            'disbursement_method' => 'bank',
            'payout_account' => [
                'bank_name' => 'Partner Bank',
                'bank_account_number' => '9876543210',
                'bank_account_name' => 'Partner User',
                'bank_code' => null,
            ],
        ]);

        Http::fake([
            'api.paystack.co/bank*' => Http::response([
                'status' => true,
                'data' => [[
                    'name' => 'Partner Bank',
                    'code' => '001',
                ]],
            ]),
            'api.paystack.co/transferrecipient' => Http::response([
                'status' => true,
                'data' => ['recipient_code' => 'RCP_partner'],
            ]),
            'api.paystack.co/transfer' => Http::response([
                'status' => true,
                'data' => ['transfer_code' => 'TRF_partner', 'status' => 'pending'],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/loans/{$loan->id}/disburse")
            ->assertStatus(202)
            ->assertJsonPath('data.disbursement.status', 'processing')
            ->assertJsonPath('data.transfer.transfer_code', 'TRF_partner');

        $disbursement = LoanDisbursement::firstOrFail();
        $this->assertDatabaseHas('loan_disbursements', [
            'id' => $disbursement->id,
            'status' => 'processing',
            'provider' => 'paystack',
        ]);
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'transferrecipient')
            && $request['account_number'] === '9876543210'
            && $request['bank_code'] === '001');
        $this->assertSame('001', $disbursement->fresh()->bank_code);

        config(['paystack.secret_key' => 'test-secret']);
        $payload = [
            'event' => 'transfer.success',
            'data' => [
                'reference' => $disbursement->fresh()->provider_reference,
                'transfer_code' => 'TRF_partner',
            ],
        ];
        $signature = hash_hmac('sha512', json_encode($payload), 'test-secret');

        $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson('/api/paystack/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('loan_disbursements', [
            'id' => $disbursement->id,
            'status' => 'disbursed',
        ]);
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => 'active',
        ]);
        $this->assertCount(3, $loan->fresh()->repaymentSchedules);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
    }

    public function test_configured_term_rate_is_split_evenly_across_installments(): void
    {
        $user = User::create([
            'fullname' => 'Two Month Borrower',
            'email' => 'two-month@example.com',
            'phone_number' => '08012345670',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Two Month Borrower',
            'bank_code' => '123',
            'password' => 'secret1234',
            'role' => 'admin',
        ]);

        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 10000,
            'interest_rate' => 5,
            'total_interest' => 500,
            'total_repayable' => 10500,
            'monthly_installment' => 5250,
            'term_months' => 2,
            'status' => 'approved',
            'approved_at' => now(),
            'application_reason' => 'Test loan',
        ]);

        LoanDisbursement::create([
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'amount' => 10000,
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
            'bank_code' => $user->bank_code,
            'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/loans/{$loan->id}/disburse")
            ->assertOk();

        $schedules = $loan->repaymentSchedules()->orderBy('installment_number')->get();
        $this->assertCount(2, $schedules);
        $this->assertSame('5250.00', $schedules[0]->total_due);
        $this->assertSame('5250.00', $schedules[1]->total_due);
    }
}
