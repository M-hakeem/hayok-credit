<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('paystack_recipient_code')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('transfer_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();
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