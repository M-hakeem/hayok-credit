<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('paystack_customer_code')->nullable();
            $table->text('authorization_code');
            $table->text('signature')->nullable();
            $table->string('email');
            $table->string('card_type')->nullable();
            $table->string('brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('bank')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('channel')->nullable();
            $table->boolean('reusable')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'reusable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_authorizations');
    }
};