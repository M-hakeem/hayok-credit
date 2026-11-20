<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('nin_first_name')->nullable()->after('nin');
            $table->text('nin_middle_name')->nullable()->after('nin_first_name');
            $table->text('nin_last_name')->nullable()->after('nin_middle_name');
            $table->text('nin_date_of_birth')->nullable()->after('nin_last_name');
            $table->text('nin_gender')->nullable()->after('nin_date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nin_first_name',
                'nin_middle_name',
                'nin_last_name',
                'nin_date_of_birth',
                'nin_gender',
            ]);
        });
    }
};
