<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_authorizations', function (Blueprint $table) {
            $table->string('authorization_code_hash', 64)->unique()->after('authorization_code');
        });
    }

    public function down(): void
    {
        Schema::table('payment_authorizations', function (Blueprint $table) {
            $table->dropUnique(['authorization_code_hash']);
            $table->dropColumn('authorization_code_hash');
        });
    }
};