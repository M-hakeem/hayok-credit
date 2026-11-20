<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('paystack_reference')->nullable()->unique()->after('processed_at');
            $table->string('paystack_recipient_code')->nullable()->after('paystack_reference');
            $table->string('transfer_code')->nullable()->after('paystack_recipient_code');
            $table->string('failure_reason')->nullable()->after('transfer_code');
            $table->json('gateway_response')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique(['paystack_reference']);
            $table->dropColumn([
                'paystack_reference',
                'paystack_recipient_code',
                'transfer_code',
                'failure_reason',
                'gateway_response',
            ]);
        });
    }
};
