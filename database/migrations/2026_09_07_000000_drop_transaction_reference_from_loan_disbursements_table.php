<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropColumn('transaction_reference');
        });
    }

    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('transaction_reference')->nullable();
        });
    }
};
