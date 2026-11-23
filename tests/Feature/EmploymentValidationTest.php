<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmploymentValidationTest extends TestCase
{
    public function test_employment_information_reports_the_configured_minimum_length(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/employment', [
            'employment_information' => 'X',
            'occupation' => 'Logistics',
            'educational_details' => 'Degree',
            'income' => 100001,
            'bank_statement' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'employment_information',
            ])
            ->assertJsonPath(
                'errors.employment_information.0',
                'Employment information must be at least 3 characters'
            );
    }

    public function test_employment_information_accepts_the_configured_minimum_length(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/employment', [
            'employment_information' => 'NYSC',
            'occupation' => 'Logistics',
            'educational_details' => 'Degree',
            'income' => 100001,
            'bank_statement' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
        ])
            ->assertCreated();
    }
}
