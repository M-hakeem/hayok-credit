<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountLevelService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountLevelProgressionTest extends TestCase
{
    public function test_both_uploaded_documents_promote_a_user_to_tier_three_and_deleting_one_demotes_them(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'nin_verified_at' => now(),
            'bvn_verified_at' => now(),
            'account_level' => 'tier_2',
        ]);
        Sanctum::actingAs($user, ['*']);

        $addressResponse = $this->post('/api/address', [
            'residential_address' => '12 Example Street, Ikeja',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'utility_bill' => UploadedFile::fake()->create('utility-bill.pdf', 100, 'application/pdf'),
        ]);
        $addressResponse->assertCreated();
        $this->assertSame('tier_2', $user->fresh()->account_level);

        $employmentResponse = $this->post('/api/employment', [
            'employment_information' => 'Full-time employed at Example Company',
            'occupation' => 'Accountant',
            'educational_details' => 'Bachelor degree in accounting',
            'income' => 250000,
            'bank_statement' => UploadedFile::fake()->create('bank-statement.pdf', 100, 'application/pdf'),
        ]);
        $employmentResponse->assertCreated();
        $this->assertSame('tier_3', $user->fresh()->account_level);

        $employmentId = $employmentResponse->json('data.id');
        $this->deleteJson('/api/employment/'.$employmentId)
            ->assertOk();
        $this->assertSame('tier_2', $user->fresh()->account_level);
    }

    public function test_identity_verification_without_uploads_cannot_reach_tier_three(): void
    {
        $user = User::factory()->create([
            'nin_verified_at' => now(),
            'bvn_verified_at' => now(),
            'account_level' => 'tier_2',
        ]);

        $this->assertSame('tier_2', app(AccountLevelService::class)->recalculate($user));
        $this->assertSame('tier_2', $user->fresh()->account_level);
    }

    public function test_user_without_verified_identity_has_no_automatic_tier(): void
    {
        $user = User::factory()->create(['account_level' => null]);

        $this->assertNull(app(AccountLevelService::class)->recalculate($user));
        $this->assertNull($user->fresh()->account_level);
    }
}
