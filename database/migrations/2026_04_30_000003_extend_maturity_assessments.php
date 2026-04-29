<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X3 — extend maturity_assessments for the 3 input methods plus
 * domain breakdown + recommendation tracking. Companion tables
 * (maturity_questions, maturity_question_responses) live in the next
 * two migrations.
 *
 * Input methods (PDF "Bentuk atau Metode Modul Maturity Assessment"):
 *   - questionnaire — DPO answers 18 questions on a 1-10 ruler
 *   - document      — upload SOP/Kebijakan/SDLC, AI scores from doc
 *                     content (Sprint X4 ships the AI scoring)
 *   - auto_derive   — service queries existing Nexus data and scores
 *                     the 18 dimensions automatically
 *
 * `domain_scores` JSON shape:
 *   { governance: 7.5, processing_basis: 6.0, controller_obligations: 5.2, security: 6.0 }
 * average per-domain across the questions in that domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('maturity_assessments', 'input_method')) {
                $table->string('input_method', 32)->default('questionnaire');
                // 'questionnaire' | 'document' | 'auto_derive'
            }
            if (!Schema::hasColumn('maturity_assessments', 'domain_scores')) {
                $table->json('domain_scores')->nullable();
            }
            if (!Schema::hasColumn('maturity_assessments', 'uploaded_doc_ids')) {
                $table->json('uploaded_doc_ids')->nullable();
            }
            if (!Schema::hasColumn('maturity_assessments', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('maturity_assessments', 'submitted_by')) {
                $table->uuid('submitted_by')->nullable();
            }
            if (!Schema::hasColumn('maturity_assessments', 'auto_derived_at')) {
                $table->timestamp('auto_derived_at')->nullable();
            }
            if (!Schema::hasColumn('maturity_assessments', 'auto_derive_metadata')) {
                $table->json('auto_derive_metadata')->nullable();
                // Snapshot of source data signals at derivation time, for audit.
            }
        });

        Schema::table('maturity_assessments', function (Blueprint $table) {
            $idx = collect(Schema::getIndexes('maturity_assessments'))->pluck('name')->all();
            if (!in_array('maturity_assessments_input_method_idx', $idx, true)) {
                $table->index('input_method', 'maturity_assessments_input_method_idx');
            }
            if (!in_array('maturity_assessments_overall_level_idx', $idx, true)) {
                $table->index('overall_level', 'maturity_assessments_overall_level_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            foreach (['input_method', 'domain_scores', 'uploaded_doc_ids',
                      'submitted_at', 'submitted_by', 'auto_derived_at',
                      'auto_derive_metadata'] as $c) {
                if (Schema::hasColumn('maturity_assessments', $c)) $table->dropColumn($c);
            }
        });
    }
};
