<?php

namespace Tests\Feature;

use App\Models\BusinessLoanApplication;
use App\Models\BusinessLoanDocument;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInterestSetting;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessLoanApplicationReviewTest extends TestCase
{
    public function test_admin_can_list_business_loan_applications(): void
    {
        $this->authenticateAdmin();
        $application = $this->createBusinessApplication('BLA-LIST000001');
        $application->documents()->create([
            'document_type' => 'bank_statements',
            'file_path' => 'business-kyb/test/bank-statements.pdf',
            'original_filename' => 'bank-statements.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->getJson('/api/admin/business-loan-applications?status=pending_review')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.0.reference', 'BLA-LIST000001')
            ->assertJsonPath('data.data.0.registered_business_name', 'Acme Limited')
            ->assertJsonPath('data.data.0.documents_count', 1);
    }

    public function test_admin_can_view_a_single_business_loan_application(): void
    {
        $this->authenticateAdmin();
        $application = $this->createBusinessApplication('BLA-SHOW000001');
        $application->stakeholders()->create([
            'full_name' => 'Ada Okafor',
            'date_of_birth' => '1990-01-01',
            'gender' => 'female',
            'nationality' => 'Nigerian',
            'identification_type' => 'NIN',
            'identification_number' => '12345678901',
            'residential_address' => '2 Owner Street, Lagos',
            'shareholding_percentage' => 60,
            'role' => 'beneficial_owner',
            'is_pep' => false,
        ]);

        $this->getJson("/api/admin/business-loan-applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference', 'BLA-SHOW000001')
            ->assertJsonPath('data.business.registered_name', 'Acme Limited')
            ->assertJsonPath('data.payout_account.bank_account_number', '0123456789')
            ->assertJsonPath('data.account_owner.email', 'ada@example.test')
            ->assertJsonCount(1, 'data.stakeholders');
    }

    public function test_admin_can_download_an_application_document_from_private_storage(): void
    {
        $this->authenticateAdmin();
        Storage::fake('local');
        $application = $this->createBusinessApplication('BLA-DOC00000001');
        $document = $application->documents()->create([
            'document_type' => 'bank_statements',
            'file_path' => 'business-kyb/BLA-DOC00000001/bank-statements.pdf',
            'original_filename' => 'bank-statements.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put($document->file_path, 'private-document');

        $this->get("/api/admin/business-loan-applications/{$application->id}/documents/{$document->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_admin_cannot_download_a_document_from_another_application(): void
    {
        $this->authenticateAdmin();
        $application = $this->createBusinessApplication('BLA-DOC00000002');
        $otherApplication = $this->createBusinessApplication('BLA-DOC00000003');
        $document = $otherApplication->documents()->create([
            'document_type' => 'bank_statements',
            'file_path' => 'business-kyb/other/bank-statements.pdf',
            'original_filename' => 'bank-statements.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->get("/api/admin/business-loan-applications/{$application->id}/documents/{$document->id}")
            ->assertNotFound();
    }

    public function test_admin_can_search_business_loan_applications(): void
    {
        $this->authenticateAdmin();
        $this->createBusinessApplication('BLA-SEARCH0001');

        $this->getJson('/api/admin/business-loan-applications?search=Acme')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.reference', 'BLA-SEARCH0001');
    }

    public function test_admin_can_approve_a_pending_business_loan_application(): void
    {
        $admin = $this->authenticateAdmin();
        $this->createInterestRate();
        $application = $this->createBusinessApplication('BLA-APPROVE0001');

        $response = $this->postJson("/api/admin/business-loan-applications/{$application->id}/approve", [
            'review_notes' => 'Documents reviewed.',
            'risk_rating' => 'Low',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.review_notes', 'Documents reviewed.')
            ->assertJsonPath('data.risk_rating', 'Low')
            ->assertJsonStructure(['data' => ['business_user_id', 'loan_id']]);

        $businessUserId = $response->json('data.business_user_id');
        $loanId = $response->json('data.loan_id');

        $this->assertDatabaseHas('business_loan_applications', [
            'id' => $application->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'business_user_id' => $businessUserId,
            'loan_id' => $loanId,
            'review_notes' => 'Documents reviewed.',
            'risk_rating' => 'Low',
        ]);
        $businessUser = User::findOrFail($businessUserId);
        $loan = Loan::findOrFail($loanId);
        $this->assertSame('Acme Limited', $businessUser->fullname);
        $this->assertSame('business@example.test', $businessUser->email);
        $this->assertSame('0123456789', $businessUser->bank_account_number);
        $this->assertSame('0123456789', $loan->payout_account['bank_account_number']);
        $this->assertNotNull($businessUser->wallet);
        $this->assertDatabaseHas('loans', [
            'id' => $loanId,
            'user_id' => $businessUser->id,
            'amount_requested' => 1000000,
            'interest_rate' => 5,
            'status' => 'pending',
            'disbursement_method' => 'bank',
            'application_reason' => 'Purchase equipment.',
        ]);
        $this->assertDatabaseMissing('loan_disbursements', ['loan_id' => $loanId]);

        $this->postJson("/api/admin/loans/{$loanId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.disbursement.status', 'pending');

        $disbursement = LoanDisbursement::where('loan_id', $loanId)->firstOrFail();
        $this->assertSame('Test Bank', $disbursement->bank_name);
        $this->assertSame('Acme Limited', $disbursement->bank_account_name);
        $this->assertSame('0123456789', $disbursement->bank_account_number);
        $this->assertSame('001', $disbursement->bank_code);
    }

    public function test_business_application_approval_fails_without_a_configured_interest_rate(): void
    {
        $this->authenticateAdmin();
        $application = $this->createBusinessApplication('BLA-NORATE00001');

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No active loan interest rate is configured for this term.');

        $this->assertDatabaseHas('business_loan_applications', [
            'id' => $application->id,
            'status' => 'pending_review',
            'business_user_id' => null,
            'loan_id' => null,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'business@example.test']);
    }

    public function test_admin_can_supply_payout_details_when_approving_an_older_application(): void
    {
        $this->authenticateAdmin();
        $this->createInterestRate();
        $application = $this->createBusinessApplication('BLA-LEGACYPAY001');
        $application->update(['payout_account' => null]);

        $response = $this->postJson("/api/admin/business-loan-applications/{$application->id}/approve", [
            'payout_account' => [
                'bank_name' => 'Updated Bank',
                'bank_code' => '002',
                'bank_account_name' => 'Acme Limited',
                'bank_account_number' => '9876543210',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $application->refresh();
        $this->assertSame('9876543210', $application->payout_account['bank_account_number']);
        $businessUser = User::findOrFail($response->json('data.business_user_id'));
        $this->assertSame('9876543210', $businessUser->bank_account_number);
        $this->assertSame('Updated Bank', $businessUser->bank_name);
    }

    public function test_business_application_approval_fails_when_a_business_user_already_exists(): void
    {
        $this->authenticateAdmin();
        $this->createInterestRate();
        User::create([
            'fullname' => 'Existing Account',
            'email' => 'business@example.test',
            'phone_number' => '+2348012345678',
            'password' => null,
        ]);
        $application = $this->createBusinessApplication('BLA-DUPLICATE001');

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A user already exists with this business email. Resolve the duplicate before approving.');

        $this->assertDatabaseHas('business_loan_applications', [
            'id' => $application->id,
            'status' => 'pending_review',
            'business_user_id' => null,
            'loan_id' => null,
        ]);
    }

    public function test_admin_can_reject_a_pending_business_loan_application_with_notes(): void
    {
        $this->authenticateAdmin();
        $application = $this->createBusinessApplication('BLA-REJECT0001');

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/reject", [
            'review_notes' => 'Required documents are incomplete.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('business_loan_applications', [
            'id' => $application->id,
            'status' => 'rejected',
            'review_notes' => 'Required documents are incomplete.',
        ]);
    }

    public function test_business_loan_application_review_requires_pending_status_and_rejection_notes(): void
    {
        $this->authenticateAdmin();
        $this->createInterestRate();
        $application = $this->createBusinessApplication('BLA-REVIEW0001');

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/approve")
            ->assertOk();

        $this->postJson("/api/admin/business-loan-applications/{$application->id}/reject", [
            'review_notes' => 'Late review attempt.',
        ])->assertUnprocessable();
    }

    public function test_non_admin_cannot_view_business_loan_applications(): void
    {
        Sanctum::actingAs(User::create([
            'phone_number' => '+2348099999999',
            'password' => 'password',
        ]));

        $this->getJson('/api/admin/business-loan-applications')->assertForbidden();
    }

    private function authenticateAdmin(): User
    {
        $admin = User::create([
            'phone_number' => '+2348088888888',
            'password' => 'password',
            'role' => 'admin',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createBusinessApplication(string $reference): BusinessLoanApplication
    {
        return BusinessLoanApplication::create([
            'reference' => $reference,
            'business_profile' => [
                'registered_name' => 'Acme Limited',
                'registration_number' => 'RC123456',
                'email' => 'business@example.test',
                'phone' => '+2348012345678',
            ],
            'payout_account' => [
                'bank_name' => 'Test Bank',
                'bank_code' => '001',
                'bank_account_name' => 'Acme Limited',
                'bank_account_number' => '0123456789',
            ],
            'account_owner' => [
                'first_name' => 'Ada',
                'email' => 'ada@example.test',
            ],
            'loan_details' => [
                'amount_requested' => 1000000,
                'term_months' => 12,
                'purpose' => 'Purchase equipment.',
            ],
            'status' => 'pending_review',
            'consent_at' => now(),
        ]);
    }

    private function createInterestRate(): void
    {
        LoanInterestSetting::create([
            'interest_rate' => 5,
            'tenure_months' => 12,
            'active' => true,
        ]);
    }
}
