<?php

namespace Tests\Feature;

use App\Models\DisbursementSetting;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use App\Services\ClaimifyLoanDisbursementService;
use App\Services\ClaimifyWalletService;
use RuntimeException;
use Tests\TestCase;

class ClaimifyLoanDisbursementServiceTest extends TestCase
{
    public function test_provider_insufficient_funds_response_is_saved_as_failed_disbursement(): void
    {
        $user = User::factory()->create();
        $loan = Loan::create([
            'user_id' => $user->id,
            'amount_requested' => 22500,
            'term_months' => 2,
            'status' => 'approved',
        ]);
        $disbursement = LoanDisbursement::create([
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'amount' => 22500,
            'bank_name' => 'Jaiz Bank',
            'bank_account_number' => '0018772122',
            'bank_account_name' => 'Test Account',
            'bank_code' => '000006',
            'status' => 'pending',
        ]);
        DisbursementSetting::create([
            'id' => 1,
            'debit_account_number' => '1234567890',
        ]);

        $claimify = \Mockery::mock(ClaimifyWalletService::class);
        $claimify->shouldReceive('nameInquiry')
            ->once()
            ->andReturn(['success' => true, 'data' => ['nameEnquiryReference' => 'enquiry-123']]);
        $claimify->shouldReceive('transfer')
            ->once()
            ->andReturn([
                'message' => 'No sufficient funds',
                'statusCode' => 400,
                'responseCode' => '51',
            ]);

        try {
            (new ClaimifyLoanDisbursementService($claimify))->disburse($disbursement);
            $this->fail('The provider error should be raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('No sufficient funds', $exception->getMessage());
        }

        $this->assertDatabaseHas('loan_disbursements', [
            'id' => $disbursement->id,
            'status' => 'failed',
            'failure_reason' => 'No sufficient funds',
        ]);
        $this->assertSame(
            'No sufficient funds',
            $disbursement->fresh()->gateway_response['message']
        );
    }
}
