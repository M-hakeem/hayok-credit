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
            $table->string('provider_reference')->nullable()->unique()->after('provider');
            $table->string('provider_transaction_id')->nullable()->after('provider_reference');
            $table->unsignedBigInteger('amount_minor')->nullable()->after('provider_transaction_id');
            $table->string('failure_reason')->nullable()->after('amount_minor');
            $table->timestamp('last_attempt_at')->nullable()->after('failure_reason');
            $table->timestamp('next_retry_at')->nullable()->after('last_attempt_at');
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('next_retry_at');
            $table->json('gateway_response')->nullable()->after('attempt_count');
            $table->json('metadata')->nullable()->after('gateway_response');
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