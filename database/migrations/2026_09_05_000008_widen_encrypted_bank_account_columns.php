<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bank_account_number')->nullable()->change();
        });

        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->text('bank_account_number')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_account_number')->nullable()->change();
        });

        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('bank_account_number')->change();
        });
    }
};