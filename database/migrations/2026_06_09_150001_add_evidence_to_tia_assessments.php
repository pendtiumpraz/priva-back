<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X5 follow-up: Per-metrik evidence upload + AI document analysis
 * untuk TIA — parity dengan GAP/Maturity Assessment.
 *
 * Shape (mirror maturity_assessments):
 *   attachments: { "<metric_code>": [{ path, url, name, driver, uploaded_at }] }
 *   ai_analyses: { "<metric_code>": [{ status, analysis, cited_passages,
 *                  confidence, tokens_used, error, analyzed_at, attachment_path }] }
 *
 * Catatan: verdict AI di TIA ADVISORY ONLY — TIDAK pernah mengubah skor
 * metrik 1-10 user dan TIDAK masuk computeOverallRisk().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('tia_assessments', 'attachments')) {
                $table->json('attachments')->nullable();
            }
            if (! Schema::hasColumn('tia_assessments', 'ai_analyses')) {
                $table->json('ai_analyses')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('tia_assessments', 'ai_analyses')) {
                $table->dropColumn('ai_analyses');
            }
            if (Schema::hasColumn('tia_assessments', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
