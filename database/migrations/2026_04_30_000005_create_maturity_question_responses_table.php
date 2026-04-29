<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-question responses for a Maturity Assessment. One row per
 * (assessment_id, question_code). Lifted out of the JSON `dimensions`
 * blob on maturity_assessments so we can query trend-over-time per
 * question + index by score for analytics.
 *
 * `source` distinguishes how the score got there:
 *   - 'manual'         operator clicked the ruler
 *   - 'auto_derive'    MaturityAutoDeriveService computed it
 *   - 'document_ai'    AI parsed an uploaded SOP and scored it (X4)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('maturity_question_responses')) {
            Schema::create('maturity_question_responses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('assessment_id');
                $table->string('question_code', 16);
                $table->string('domain', 40);
                $table->unsignedTinyInteger('score');                  // 1-10
                $table->text('notes')->nullable();
                $table->string('source', 32)->default('manual');
                $table->json('source_metadata')->nullable();
                // For auto_derive: which queries fed the score, signal counts, etc.
                $table->timestamps();

                $table->unique(['assessment_id', 'question_code']);
                $table->index(['assessment_id', 'domain']);
                $table->foreign('assessment_id')
                      ->references('id')->on('maturity_assessments')
                      ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_question_responses');
    }
};
