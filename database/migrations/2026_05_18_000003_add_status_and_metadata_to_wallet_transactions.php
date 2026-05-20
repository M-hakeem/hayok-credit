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
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'success', 'failed', 'reversed'])->default('pending')->after('reference');
            $table->string('transaction_category')->nullable()->after('status');
            $table->string('channel')->nullable()->after('transaction_category');
            $table->index(['wallet_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['wallet_id', 'status']);
            $table->dropColumn(['status', 'transaction_category', 'channel']);
        });
    }
};
