<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('password');
            $table->string('kyc_status')->default('pending')->after('status');
            $table->string('account_level')->nullable()->after('kyc_status');
            $table->boolean('is_blacklisted')->default(false)->after('account_level');
            $table->unique('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['status', 'kyc_status', 'account_level', 'is_blacklisted']);
        });
    }
};
