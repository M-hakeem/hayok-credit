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
        Schema::table('users', function (Blueprint $table) {
            $table->string('state')->nullable()->change();
            $table->string('lga')->nullable()->change();
            $table->string('bank_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('state')->nullable(false)->change();
            $table->string('lga')->nullable(false)->change();
            $table->string('bank_code')->nullable(false)->change();
        });
    }
};
