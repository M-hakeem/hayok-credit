<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Employment;
use App\Models\Guarantor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UserProfileVerificationStatusTest extends TestCase
{
    public function test_admin_can_update_verification_statuses_without_changing_details(): void
    {
        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phone_number' => '08012345671',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $customer = User::create([
            'fullname' => 'Customer User',
            'email' => 'customer@example.com',
            'phone_number' => '08012345672',
            'password' => 'password',
            'role' => 'user',
        ]);

        $address = Address::create([
            'user_id' => $customer->id,
            'residential_address' => 'Customer residential address',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
        ]);
        $employment = Employment::create([
            'user_id' => $customer->id,
            'employment_information' => 'Full-time employee',
            'occupation' => 'Accountant',
            'educational_details' => 'Bachelor degree',
            'income' => 100000,
        ]);
        $guarantor = Guarantor::create([
            'user_id' => $customer->id,
            'guarantor_type' => '1st',
            'relationship' => 'Sibling',
            'name' => 'Customer Guarantor',
            'phone_number' => '08012345673',
            'id_type' => 'NIN',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/addresses/{$address->id}/verification-status", [
                'verification_status' => 'verified',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->putJson("/api/admin/employments/{$employment->id}/verification-status", [
            'verification_status' => 'rejected',
        ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->putJson("/api/admin/guarantors/{$guarantor->id}/verification-status", [
            'verification_status' => 'verified',
        ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->put("/api/address/{$address->id}", [
            'residential_address' => 'Admin changed address',
            'state' => 'Ogun',
            'lga' => 'Abeokuta',
            'utility_bill' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->put("/api/employment/{$employment->id}", [
            'employment_information' => 'Admin changed employment',
            'occupation' => 'Manager',
            'educational_details' => 'Masters degree',
            'income' => 500000,
            'bank_statement' => UploadedFile::fake()->create('statement.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->put("/api/guarantor/{$guarantor->id}", [
            'guarantor_type' => '1st',
            'relationship' => 'Parent',
            'name' => 'Admin changed guarantor',
            'phone_number' => '08012345675',
            'id_type' => 'BVN',
            'id_file' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'residential_address' => 'Customer residential address',
            'verification_status' => 'verified',
        ]);
        $this->assertDatabaseHas('employments', [
            'id' => $employment->id,
            'occupation' => 'Accountant',
            'verification_status' => 'rejected',
        ]);
        $this->assertDatabaseHas('guarantors', [
            'id' => $guarantor->id,
            'name' => 'Customer Guarantor',
            'verification_status' => 'verified',
        ]);
    }

    public function test_non_admin_cannot_update_verification_statuses(): void
    {
        $customer = User::create([
            'fullname' => 'Customer User',
            'email' => 'customer@example.com',
            'phone_number' => '08012345672',
            'password' => 'password',
            'role' => 'user',
        ]);
        $employment = Employment::create([
            'user_id' => $customer->id,
            'employment_information' => 'Full-time employee',
            'occupation' => 'Accountant',
            'educational_details' => 'Bachelor degree',
            'income' => 100000,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->putJson("/api/admin/employments/{$employment->id}/verification-status", [
                'verification_status' => 'verified',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('employments', [
            'id' => $employment->id,
            'verification_status' => 'pending',
        ]);
    }
}
