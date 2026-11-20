<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('paystack_recipient_code')->nullable()->after('disbursed_at');
            $table->string('provider')->nullable()->after('paystack_recipient_code');
            $table->string('provider_reference')->nullable()->unique()->after('provider');
            $table->string('transfer_code')->nullable()->after('provider_reference');
            $table->string('failure_reason')->nullable()->after('transfer_code');
            $table->json('gateway_response')->nullable()->after('failure_reason');
            $table->json('metadata')->nullable()->after('gateway_response');
        });
    }

    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropColumn(['paystack_recipient_code', 'provider', 'provider_reference', 'transfer_code', 'failure_reason', 'gateway_response', 'metadata']);
        });
    }
};