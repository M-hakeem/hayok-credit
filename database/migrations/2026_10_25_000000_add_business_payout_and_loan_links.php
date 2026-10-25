<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_loan_applications', function (Blueprint $table) {
            $table->longText('payout_account')->nullable()->after('business_profile');
            $table->foreignId('business_user_id')->nullable()->after('review_notes')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->after('business_user_id')
                ->constrained('loans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_loan_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_id');
            $table->dropConstrainedForeignId('business_user_id');
            $table->dropColumn('payout_account');
        });
    }
};
