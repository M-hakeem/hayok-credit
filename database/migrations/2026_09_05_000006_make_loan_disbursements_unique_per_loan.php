<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->unique('loan_id', 'loan_disbursements_loan_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropUnique('loan_disbursements_loan_id_unique');
        });
    }
};