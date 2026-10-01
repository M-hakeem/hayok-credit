<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->renameColumn('otp', 'otp_hash');
            $table->dropColumn('pin_id');
        });

        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->string('delivery_channel')->default('sms');
            $table->string('delivery_status')->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('message_id')->nullable()->index();
            $table->string('purpose')->default('registration');
        });

        DB::table('phone_verifications')->update([
            'otp_hash' => null,
            'verified' => false,
            'expires_at' => now(),
            'delivery_status' => 'expired',
        ]);
    }

    public function down(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
            $table->dropColumn(['delivery_channel', 'delivery_status', 'attempt_count', 'message_id', 'purpose']);
            $table->renameColumn('otp_hash', 'otp');
            $table->string('pin_id')->nullable();
        });
    }
};
