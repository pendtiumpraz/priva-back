<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X5 follow-up: Per-pertanyaan evidence upload + AI document
 * analysis untuk LIA — parity dengan GAP/Maturity Assessment.
 *
 * Shape (mirror maturity_assessments):
 *   attachments: { "<question_code>": [{ path, url, name, driver, uploaded_at }] }
 *   ai_analyses: { "<question_code>": [{ status, analysis, cited_passages,
 *                  confidence, tokens_used, error, analyzed_at, attachment_path }] }
 *
 * Catatan: LIA kualitatif — TIDAK ada scoring. Verdict AI murni advisory
 * (badge di UI); keputusan lulus/tidak_lulus tetap manual oleh Approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('lia_assessments', 'attachments')) {
                $table->json('attachments')->nullable();
            }
            if (! Schema::hasColumn('lia_assessments', 'ai_analyses')) {
                $table->json('ai_analyses')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('lia_assessments', 'ai_analyses')) {
                $table->dropColumn('ai_analyses');
            }
            if (Schema::hasColumn('lia_assessments', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
