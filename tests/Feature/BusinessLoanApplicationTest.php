<?php

namespace Tests\Feature;

use App\Models\BusinessLoanApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessLoanApplicationTest extends TestCase
{
    public function test_partner_can_submit_a_business_loan_application_for_review(): void
    {
        Storage::fake('public');
        config(['services.partner.api_key' => 'partner-key']);

        $documentTypes = [
            'account_owner_identity_document',
            'account_owner_address_proof',
            'account_owner_passport_photograph',
            'certificate_of_incorporation',
            'memorandum_and_articles',
            'directors_status_report',
            'business_address_proof',
            'tin_certificate',
            'board_resolution',
            'financial_statements',
            'bank_statements',
        ];

        $documents = array_map(fn (string $type) => [
            'type' => $type,
            'file' => UploadedFile::fake()->create("{$type}.pdf", 10, 'application/pdf'),
        ], $documentTypes);

        $payload = [
            'nature_of_business' => 'Agricultural produce distribution',
            'product_use_case' => 'Loan disbursement',
            'expected_monthly_transaction_volume' => 50,
            'expected_monthly_transaction_value' => 5000000,
            'source_of_funds' => 'Produce sales revenue',
            'business' => [
                'registered_name' => 'Hayok Produce Limited',
                'registration_number' => 'RC123456',
                'type' => 'limited_liability_company',
                'industry' => 'Agriculture',
                'incorporation_date' => '2020-01-01',
                'registration_country' => 'Nigeria',
                'registration_state' => 'Lagos',
                'registered_office_address' => '1 Market Road, Lagos, Nigeria',
                'tin' => '12345678-0001',
                'email' => 'business@example.test',
                'phone' => '+2348012345678',
            ],
            'account_owner' => [
                'first_name' => 'Ada',
                'last_name' => 'Okafor',
                'date_of_birth' => '1990-01-01',
                'gender' => 'female',
                'nationality' => 'Nigerian',
                'identification_type' => 'NIN',
                'identification_number' => '12345678901',
                'government_id_number' => 'NIN-12345678901',
                'government_id_expires_at' => '2030-01-01',
                'phone' => '+2348012345679',
                'email' => 'ada@example.test',
                'residential_address' => '2 Owner Street, Lagos, Nigeria',
                'shareholding_percentage' => 60,
                'role' => 'CEO',
            ],
            'stakeholders' => [[
                'full_name' => 'Ada Okafor',
                'date_of_birth' => '1990-01-01',
                'gender' => 'female',
                'nationality' => 'Nigerian',
                'identification_type' => 'NIN',
                'identification_number' => '12345678901',
                'residential_address' => '2 Owner Street, Lagos, Nigeria',
                'shareholding_percentage' => 60,
                'role' => 'beneficial_owner',
                'is_pep' => false,
                'identity_document' => UploadedFile::fake()->create('owner-id.pdf', 10, 'application/pdf'),
                'proof_of_address' => UploadedFile::fake()->create('owner-address.pdf', 10, 'application/pdf'),
            ]],
            'documents' => $documents,
            'loan' => [
                'amount_requested' => 1000000,
                'term_months' => 12,
                'purpose' => 'Purchase farm produce for resale.',
                'repayment_plan' => 'Monthly direct debit from business account.',
            ],
            'consent' => true,
        ];

        $response = $this
            ->withHeader('X-Partner-Key', 'partner-key')
            ->post('/api/partner/business-loan-applications', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.document_count', 13)
            ->assertJsonPath('data.stakeholder_count', 1);

        $application = BusinessLoanApplication::firstOrFail();
        $this->assertCount(13, $application->documents);
        $this->assertCount(1, $application->stakeholders);
        Storage::disk('public')->assertExists($application->documents->first()->file_path);
    }

    public function test_business_application_requires_partner_authentication(): void
    {
        $this->post('/api/partner/business-loan-applications')->assertUnauthorized();
    }
}
