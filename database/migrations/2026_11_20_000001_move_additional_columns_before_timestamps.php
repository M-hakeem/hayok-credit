<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE users MODIFY created_at TIMESTAMP NULL AFTER transaction_pin_hash, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE loans MODIFY created_at TIMESTAMP NULL AFTER payout_account, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE withdrawals MODIFY created_at TIMESTAMP NULL AFTER gateway_response, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE phone_verifications MODIFY created_at TIMESTAMP NULL AFTER purpose, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE guarantors MODIFY created_at TIMESTAMP NULL AFTER verification_status, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
    }

    public function down(): void
    {
        // Column order has no effect on application behavior and is intentionally not reversed.
    }
};
