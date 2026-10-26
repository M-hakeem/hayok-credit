<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Guarantor;
use App\Models\LoanInterestSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerLoanApplicationTest extends TestCase
{
    public function test_partner_application_saves_nested_address_and_guarantor_with_multipart_upload(): void
    {
        Storage::fake('public');
        config(['services.partner.api_key' => 'partner-key']);
        LoanInterestSetting::create([
            'interest_rate' => 5,
            'tenure_months' => 12,
            'active' => true,
        ]);

        $response = $this
            ->withHeader('X-Partner-Key', 'partner-key')
            ->withHeader('Accept', 'application/json')
            ->post('/api/partner/loan-application', [
                'organisation_name' => 'Test Organisation',
                'phone_number' => '08012345670',
                'fullname' => 'Test Applicant',
                'email' => 'applicant@example.test',
                'dob' => '1990-01-01',
                'gender' => 'female',
                'nin' => '12345678901',
                'bvn' => '10987654321',
                'address' => [
                    'residential_address' => '12 Example Street, Lagos',
                    'state' => 'Lagos',
                    'lga' => 'Ikeja',
                ],
                'bank_name' => 'Test Bank',
                'bank_account_number' => '0123456789',
                'bank_account_name' => 'Test Applicant',
                'bank_code' => '001',
                'amount_requested' => 100000,
                'term_months' => 12,
                'guarantors' => [[
                    'guarantor_type' => '1st',
                    'name' => 'Test Guarantor',
                    'phone_number' => '08012345671',
                    'relationship' => 'Sibling',
                    'id_type' => 'NIN',
                    'id_file' => UploadedFile::fake()->createWithContent(
                        'guarantor-id.pdf',
                        "%PDF-1.4\nTest guarantor ID\n%%EOF"
                    ),
                ]],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.address.residential_address', '12 Example Street, Lagos')
            ->assertJsonPath('data.address.state', 'Lagos')
            ->assertJsonPath('data.address.lga', 'Ikeja')
            ->assertJsonPath('data.loan.disbursement_method', 'bank')
            ->assertJsonMissingPath('data.loan.payout_account');

        $address = Address::query()->firstOrFail();
        $guarantor = Guarantor::query()->firstOrFail();
        $loan = \App\Models\Loan::query()->firstOrFail();

        $this->assertSame('12 Example Street, Lagos', $address->residential_address);
        $this->assertSame('Lagos', $address->state);
        $this->assertSame('Ikeja', $address->lga);
        $this->assertSame('bank', $loan->disbursement_method);
        $this->assertSame('0123456789', $loan->payout_account['bank_account_number']);
        $this->assertNotNull($guarantor->id_file_path);
        Storage::disk('public')->assertExists($guarantor->id_file_path);

        $this->withHeader('X-Partner-Key', 'partner-key')
            ->withHeader('Accept', 'application/json')
            ->post('/api/partner/loan-application', [
                'organisation_name' => 'Test Organisation',
                'phone_number' => '08012345670',
                'bank_name' => 'Test Bank',
                'bank_account_number' => '0123456789',
                'bank_account_name' => 'Test Applicant',
                'bank_code' => '001',
                'amount_requested' => 100000,
                'term_months' => 12,
                'guarantors' => [[
                    'guarantor_type' => '1st',
                    'name' => 'Test Guarantor',
                    'phone_number' => '08012345671',
                    'relationship' => 'Sibling',
                    'id_type' => 'NIN',
                    'id_file' => UploadedFile::fake()->create('guarantor-id.txt', 1, 'text/plain'),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guarantors.0.id_file');

        $this->withHeader('X-Partner-Key', 'partner-key')
            ->postJson('/api/partner/loan-application', [
                'organisation_name' => 'Test Organisation',
                'phone_number' => '08012345670',
                'bank_name' => 'Test Bank',
                'bank_account_number' => '0123456789',
                'bank_account_name' => 'Test Applicant',
                'bank_code' => '001',
                'residential_address' => '34 Legacy Address Road, Lagos',
                'state' => 'Ogun',
                'lga' => 'Abeokuta South',
                'amount_requested' => 100000,
                'term_months' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.address.residential_address', '34 Legacy Address Road, Lagos')
            ->assertJsonPath('data.address.state', 'Ogun')
            ->assertJsonPath('data.address.lga', 'Abeokuta South');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'residential_address' => '34 Legacy Address Road, Lagos',
            'state' => 'Ogun',
            'lga' => 'Abeokuta South',
        ]);
    }
}
