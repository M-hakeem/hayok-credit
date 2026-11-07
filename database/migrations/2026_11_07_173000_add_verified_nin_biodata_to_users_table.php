<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('nin_first_name')->nullable();
            $table->text('nin_middle_name')->nullable();
            $table->text('nin_last_name')->nullable();
            $table->text('nin_date_of_birth')->nullable();
            $table->text('nin_gender')->nullable();
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
