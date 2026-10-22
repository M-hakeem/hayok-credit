<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use softdelete\SoftDeletes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_loan_limits', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_loan_limits');
    }
};
