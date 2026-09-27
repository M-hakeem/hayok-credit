<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->change();
            $table->text('bank_account_number')->nullable()->change();
            $table->string('bank_account_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('bank_name')->nullable(false)->change();
            $table->text('bank_account_number')->nullable(false)->change();
            $table->string('bank_account_name')->nullable(false)->change();
        });
    }
};
