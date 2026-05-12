<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G.9: Cache per-question AI document analysis result inside the
 * assessment row.
 *
 * Shape:
 *   {
 *     "<question_id>": {
 *       "status": "comply"|"partial"|"non_comply"|"unsure",
 *       "analysis": "...",
 *       "cited_passages": [{"page": int|null, "text": "..."}],
 *       "confidence": 0..1,
 *       "tokens_used": int,
 *       "error": null|"...",
 *       "analyzed_at": iso8601
 *     }
 *   }
 *
 * Kept on the assessment (instead of a separate table) because the result
 * is small, single-tenant (org_id of the parent row), and almost always
 * fetched together with the assessment detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('gap_assessments', 'ai_analyses')) {
                $table->json('ai_analyses')->nullable()->after('attachments');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('gap_assessments', 'ai_analyses')) {
                $table->dropColumn('ai_analyses');
            }
        });
    }
};
