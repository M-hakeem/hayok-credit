<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAddressColumnsMigrationTest extends TestCase
{
    public function test_user_address_fields_are_moved_to_addresses_before_the_columns_are_removed(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('residential_address')->nullable();
            $table->string('state')->nullable();
            $table->string('lga')->nullable();
        });

        $userId = DB::table('users')->insertGetId([
            'fullname' => 'Address Migration User',
            'dob' => '1990-01-01',
            'gender' => 'female',
            'email' => 'address-migration@example.test',
            'residential_address' => '12 Example Street',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'phone_number' => '+2348000000199',
            'password' => bcrypt('password'),
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_09_27_120000_move_user_address_fields_to_addresses_table.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'residential_address'));
        $this->assertFalse(Schema::hasColumn('users', 'state'));
        $this->assertFalse(Schema::hasColumn('users', 'lga'));
        $this->assertDatabaseHas('addresses', [
            'user_id' => $userId,
            'residential_address' => '12 Example Street',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
        ]);
    }
}
