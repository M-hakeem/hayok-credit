<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bvn_first_name')->nullable()->after('bvn');
            $table->text('bvn_middle_name')->nullable()->after('bvn_first_name');
            $table->text('bvn_last_name')->nullable()->after('bvn_middle_name');
            $table->text('bvn_date_of_birth')->nullable()->after('bvn_last_name');
            $table->text('bvn_gender')->nullable()->after('bvn_date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bvn_first_name',
                'bvn_middle_name',
                'bvn_last_name',
                'bvn_date_of_birth',
                'bvn_gender',
            ]);
        });
    }
};
