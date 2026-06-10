<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X5: Per-question evidence upload + AI document analysis untuk
 * Maturity Assessment — parity dengan GAP Assessment.
 *
 * Shape (mirror gap_assessments):
 *   attachments: { "<question_code>": [{ path, url, name, driver, uploaded_at }] }
 *   ai_analyses: { "<question_code>": [{ status, analysis, cited_passages,
 *                  confidence, tokens_used, error, analyzed_at, attachment_path }] }
 *
 * Catatan: berbeda dengan GAP, verdict AI di Maturity TIDAK pernah
 * meng-override skor slider 1-10 user — murni advisory (badge di UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('maturity_assessments', 'attachments')) {
                $table->json('attachments')->nullable();
            }
            if (! Schema::hasColumn('maturity_assessments', 'ai_analyses')) {
                $table->json('ai_analyses')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('maturity_assessments', 'ai_analyses')) {
                $table->dropColumn('ai_analyses');
            }
            if (Schema::hasColumn('maturity_assessments', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
