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
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->foreignId('repayment_schedule_id')->nullable()->after('loan_id')->constrained('repayment_schedules')->nullOnDelete();
            $table->index(['loan_id', 'repayment_schedule_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropForeign(['repayment_schedule_id']);
            $table->dropColumn('repayment_schedule_id');
        });
    }
};
