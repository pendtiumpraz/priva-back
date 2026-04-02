<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ========== Vendors ==========
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('type')->nullable(); // processor, controller, sub-processor
            $table->string('country')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('dpa_status')->default('none'); // none, draft, signed, expired
            $table->date('dpa_signed_at')->nullable();
            $table->date('dpa_expires_at')->nullable();
            $table->integer('risk_score')->default(0); // 0-100
            $table->string('risk_level')->default('belum_dinilai'); // low, medium, high, critical, belum_dinilai
            $table->date('last_assessed_at')->nullable();
            $table->json('data_shared')->nullable(); // types of personal data shared
            $table->json('services_provided')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
            $table->index('risk_level');
            $table->index('dpa_status');
        });

        // ========== Vendor Assessments ==========
        Schema::create('vendor_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id');
            $table->uuid('org_id');
            $table->uuid('assessed_by')->nullable(); // user_id
            $table->json('answers'); // structured Q&A
            $table->integer('score')->default(0); // 0-100
            $table->string('risk_level')->default('medium'); // low, medium, high, critical
            $table->json('recommendations')->nullable(); // AI-generated or manual
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('vendor_id')->references('id')->on('vendors')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('vendor_id');
            $table->index('org_id');
        });

        // ========== Cross-Border Data Transfers ==========
        Schema::create('cross_border_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('destination_country');
            $table->string('destination_entity')->nullable(); // recipient org name
            $table->string('transfer_purpose')->nullable();
            $table->json('data_categories')->nullable(); // types of data transferred
            $table->string('legal_basis')->nullable(); // consent, adequacy, sccs, bcr, derogation
            $table->json('safeguards')->nullable(); // encryption, pseudonymization, etc.
            $table->string('status')->default('pending'); // pending, approved, active, suspended
            $table->text('tia_summary')->nullable(); // Transfer Impact Assessment
            $table->json('tia_answers')->nullable();
            $table->integer('risk_score')->default(0);
            $table->string('risk_level')->default('medium');
            $table->date('approved_at')->nullable();
            $table->date('review_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
            $table->index('status');
            $table->index('destination_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_border_transfers');
        Schema::dropIfExists('vendor_assessments');
        Schema::dropIfExists('vendors');
    }
};
