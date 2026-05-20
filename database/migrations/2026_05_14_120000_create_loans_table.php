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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_requested', 12, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('total_interest', 12, 2)->default(0);
            $table->decimal('total_repayable', 12, 2)->default(0);
            $table->decimal('monthly_installment', 12, 2)->default(0);
            $table->unsignedSmallInteger('term_months');
            $table->string('status')->default('pending');
            $table->text('application_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
