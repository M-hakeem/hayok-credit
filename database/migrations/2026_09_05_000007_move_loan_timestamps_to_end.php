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

        DB::statement('ALTER TABLE loans MODIFY created_at TIMESTAMP NULL AFTER risk_grade, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE loan_payments MODIFY created_at TIMESTAMP NULL AFTER metadata, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE repayment_schedules MODIFY created_at TIMESTAMP NULL AFTER failure_reason, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
        DB::statement('ALTER TABLE loan_disbursements MODIFY created_at TIMESTAMP NULL AFTER metadata, MODIFY updated_at TIMESTAMP NULL AFTER created_at, MODIFY deleted_at TIMESTAMP NULL AFTER updated_at');
    }

    public function down(): void
    {
        // Column order has no effect on application behavior and is intentionally not reversed.
    }
};