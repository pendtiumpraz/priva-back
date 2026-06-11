<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org customisation tables for the TPRM Pre-Assessment triage catalog.
 *
 * Mirror of lia_question_overrides + custom_lia_questions:
 *   - triage_question_overrides  — copy-on-write override for a DEFAULT triage
 *     question (text/description/is_core/is_active). NULL = use default value;
 *     is_active=false = default question deactivated for this org (reversible
 *     tombstone — defaults can never be permanently deleted).
 *   - custom_triage_questions    — org-defined extra triage questions
 *     (question_code auto CUST-N). is_core drives the scope suggestion just
 *     like a default core question.
 *
 * The DEFAULT catalog lives platform-level in
 * VendorPreAssessment::DEFAULT_QUESTIONS; these tables only carry deltas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_question_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('question_code', 64);
            $table->string('text', 1000)->nullable();
            $table->text('description')->nullable();
            // null = pakai default is_core; true/false = override sifat decisive.
            $table->boolean('is_core')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'triage_question_override_org_code_unique');
        });

        Schema::create('custom_triage_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('question_code', 16);   // CUST-1, CUST-2, ...
            $table->string('text', 1000);
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'custom_triage_question_org_code_unique');
            $table->index(['org_id', 'is_active'], 'custom_triage_question_org_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_triage_questions');
        Schema::dropIfExists('triage_question_overrides');
    }
};
