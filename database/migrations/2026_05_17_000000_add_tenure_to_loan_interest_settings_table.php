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
        Schema::table('loan_interest_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('tenure_months')->nullable()->after('interest_rate')->comment('Tenure in months (e.g., 3,6,12). Null means default rate');
            $table->index(['tenure_months', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_interest_settings', function (Blueprint $table) {
            $table->dropIndex(['tenure_months', 'active']);
            $table->dropColumn('tenure_months');
        });
    }
};
