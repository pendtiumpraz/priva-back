<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X5 follow-up: AI document analysis untuk evidence TPRM
 * (Vendor Risk) — parity dengan GAP/Maturity/TIA.
 *
 * Catatan shape: BERBEDA dengan TIA/Maturity, TPRM TIDAK menambah kolom
 * `attachments` — evidence per pertanyaan SUDAH hidup di dua tempat:
 *   1. answers[<question_id>].evidence[] (JSON embed, source untuk public page)
 *   2. tabel vendor_assessment_evidence (source untuk Reviewer/Approver UI)
 * Jangan duplikasi. Migration ini hanya menambah hasil analisis AI:
 *
 *   ai_analyses: { "<question_id>": [{ status, analysis, cited_passages,
 *                  confidence, tokens_used, error, analyzed_at, attachment_path }] }
 *
 * Keyed by attachment_path supaya banyak dokumen di 1 pertanyaan bisa punya
 * verdict masing-masing — works untuk evidence dari public token flow MAUPUN
 * upload internal (storage shape sama).
 *
 * PENTING: verdict AI di TPRM ADVISORY ONLY — TIDAK pernah menyentuh
 * ThirdPartyAssessmentScorer / skor assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_assessments', 'ai_analyses')) {
                $table->json('ai_analyses')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_assessments', 'ai_analyses')) {
                $table->dropColumn('ai_analyses');
            }
        });
    }
};
