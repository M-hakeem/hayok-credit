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
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('guarantor_type', ['1st', '2nd', '3rd']);
            $table->string('relationship');
            $table->string('name');
            $table->string('phone_number');
            $table->timestamps();
            $table->softDeletes();

            // Ensure only one guarantor per type per user
            $table->unique(['user_id', 'guarantor_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guarantors');
    }
};
