<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->json('business_profile');
            $table->longText('account_owner');
            $table->json('loan_details');
            $table->string('status')->default('pending_review')->index();
            $table->string('risk_rating')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('consent_at');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_loan_application_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('gender');
            $table->string('nationality');
            $table->string('identification_type');
            $table->text('identification_number');
            $table->date('identification_expires_at')->nullable();
            $table->text('residential_address');
            $table->decimal('shareholding_percentage', 5, 2)->default(0);
            $table->string('role');
            $table->boolean('is_pep')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_loan_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_loan_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('pending_review')->index();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_loan_documents');
        Schema::dropIfExists('business_stakeholders');
        Schema::dropIfExists('business_loan_applications');
    }
};