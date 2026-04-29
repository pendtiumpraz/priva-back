<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — TPRM enrichment.
 *
 * Adds `category` to vendors so the deterministic questionnaire engine
 * knows which question set to ask (cloud infrastructure asks different
 * things than a marketing agency).
 *
 * Also extends vendor_assessments with the source enum + question-by-
 * question breakdown so an auditor can later trace why a particular
 * score was awarded — same audit-replay pattern as Maturity Assessment.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Structured category — drives which questionnaire set is
            // surfaced. NULL allowed for legacy rows where category was
            // never asked.
            $table->string('category')->nullable()->after('type');
            // Assessment cadence — high-risk vendors should be re-assessed
            // at minimum annually. Reminder UI uses this.
            $table->date('next_assessment_due_at')->nullable()->after('last_assessed_at');
        });

        Schema::table('vendor_assessments', function (Blueprint $table) {
            // Which questionnaire produced the score — 'deterministic'
            // (Phase 2 manual wizard), 'ai' (existing AI Audit Wizard),
            // 'imported' (legacy data).
            $table->string('source')->default('deterministic')->after('answers');
            // Snapshot of category at assessment time (vendor.category may
            // change later but past assessments lock to their original
            // questionnaire version).
            $table->string('category')->nullable()->after('source');
            // Per-question breakdown — array of { question_code, answer,
            // weight_applied, score_contribution }. Lets the UI show
            // "this is why your score is 67" without re-computing.
            $table->jsonb('score_breakdown')->nullable()->after('category');
            // Questionnaire version used (so a later UU PDP amendment can
            // ship a v2 questionnaire without invalidating past assessments).
            $table->string('questionnaire_version')->default('v1')->after('score_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['category', 'next_assessment_due_at']);
        });
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->dropColumn(['source', 'category', 'score_breakdown', 'questionnaire_version']);
        });
    }
};
