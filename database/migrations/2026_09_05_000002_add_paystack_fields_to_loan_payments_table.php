<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->foreignId('payment_authorization_id')->nullable()->after('repayment_schedule_id')->constrained('payment_authorizations')->nullOnDelete();
            $table->string('provider')->nullable()->after('status');
            $table->string('provider_reference')->nullable()->unique();
            $table->string('provider_transaction_id')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_authorization_id']);
            $table->dropUnique(['provider_reference']);
            $table->dropColumn(['payment_authorization_id', 'provider', 'provider_reference', 'provider_transaction_id', 'amount_minor', 'failure_reason', 'last_attempt_at', 'next_retry_at', 'attempt_count', 'gateway_response', 'metadata']);
        });
    }
};