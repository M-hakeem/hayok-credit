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
            if (!Schema::hasColumn('users', 'nin')) {
                $table->string('nin')->nullable()->after('lga');
            }
            if (!Schema::hasColumn('users', 'bvn')) {
                $table->string('bvn')->nullable()->after('nin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(array_filter(['nin', 'bvn'], fn($col) => Schema::hasColumn('users', $col)));
        });
    }
};
