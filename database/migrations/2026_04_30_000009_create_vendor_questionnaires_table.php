<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Vendor questionnaire bank. Platform-level (no org_id) —
 * same set applies to every tenant. Versioned so a UU PDP / POJK
 * amendment can ship a v2 questionnaire without invalidating past
 * assessments — VendorAssessment.questionnaire_version locks each
 * assessment to its original wording.
 *
 * Structure mirrors the SIG-Lite + CAIQ vendor questionnaire models
 * adapted for UU PDP Pasal 51 (controller's duty over processor) +
 * POJK 11/2022 (TI risk management for FI).
 *
 * Each row = one question with:
 *   - weight (1-10)        — relative importance
 *   - direction (+1/-1)    — does "yes" raise or lower the risk score
 *   - answer_type          — yes_no | multi_choice | scale_1_5
 *   - answer_options JSON  — for multi_choice, the list of options
 *                            with per-option score contribution
 *
 * Score formula (in VendorRiskScoreService):
 *   base = 50
 *   for each question Q with answer A:
 *     contribution = weight(Q) × direction(Q) × answer_normalized(A)
 *   score = clamp(0, 100, base + sum(contributions))
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_questionnaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category');                // cloud_infrastructure | saas | data_processor | ...
            $table->string('version')->default('v1');
            $table->string('question_code', 16);       // e.g. CLD-01, SAAS-03, PROC-07
            $table->string('section')->nullable();     // 'governance' | 'security' | 'data_handling' | 'compliance' | 'contractual'
            $table->text('question_text');
            $table->text('description')->nullable();   // helper text shown beneath the question
            $table->string('regulation_ref')->nullable(); // e.g. "UU PDP Pasal 51", "POJK 11/2022"
            $table->string('answer_type');             // 'yes_no' | 'multi_choice' | 'scale_1_5'
            $table->jsonb('answer_options')->nullable(); // [{value, label, score_contribution}]
            $table->tinyInteger('weight')->default(5);   // 1-10
            $table->tinyInteger('direction')->default(1); // +1 = positive answer raises score; -1 = inverted
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category', 'version', 'question_code']);
            $table->index(['category', 'version', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_questionnaires');
    }
};
