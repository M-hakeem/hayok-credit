<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayment_schedules', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->index(['status', 'due_date', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('repayment_schedules', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date', 'next_attempt_at']);
            $table->dropColumn(['paid_at', 'retry_count', 'last_attempt_at', 'next_attempt_at', 'failure_reason']);
        });
    }
};