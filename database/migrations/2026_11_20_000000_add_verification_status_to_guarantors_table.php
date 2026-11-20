<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])
                ->default('pending')
                ->after('id_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });
    }
};
