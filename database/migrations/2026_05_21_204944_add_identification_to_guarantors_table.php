<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->enum('id_type', ['NIN', 'BVN', 'Drivers License', 'International Passport', 'Voters Card'])->after('phone_number');
            $table->string('id_file_path')->nullable()->after('id_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn(['id_type', 'id_file_path']);
        });
    }
};
