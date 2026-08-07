<?php

namespace Tests\Feature;

use App\Models\BusinessLoanApplication;
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
            ->assertJsonPath('data.account_owner.email', 'ada@example.test')
            ->assertJsonCount(1, 'data.stakeholders');
    }

    public function test_non_admin_cannot_view_business_loan_applications(): void
    {
        Sanctum::actingAs(User::create([
            'phone_number' => '+2348099999999',
            'password' => 'password',
        ]));

        $this->getJson('/api/admin/business-loan-applications')->assertForbidden();
    }

    private function authenticateAdmin(): void
    {
        Sanctum::actingAs(User::create([
            'phone_number' => '+2348088888888',
            'password' => 'password',
            'role' => 'admin',
        ]));
    }

    private function createBusinessApplication(string $reference): BusinessLoanApplication
    {
        return BusinessLoanApplication::create([
            'reference' => $reference,
            'business_profile' => [
                'registered_name' => 'Acme Limited',
                'registration_number' => 'RC123456',
            ],
            'account_owner' => [
                'first_name' => 'Ada',
                'email' => 'ada@example.test',
            ],
            'loan_details' => [
                'amount_requested' => 1000000,
                'term_months' => 12,
            ],
            'status' => 'pending_review',
            'consent_at' => now(),
        ]);
    }
}
