<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding Compliance Assessment — Questions (per template).
 *
 * Field mirror struktur GAP question bank: kategori/subkategori (grouping),
 * weight (scoring), recommendation (saat jawaban 'no'/'partial'), regulation_ref
 * (pasal), requires_evidence (apakah pihak yang dinilai wajib upload bukti).
 * Tidak pakai JSON bank + override seperti GAP — di sini pertanyaan dinormalkan
 * per template karena seluruhnya di-author oleh holding.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('holding_assessment_questions')) {
            return;
        }
        Schema::create('holding_assessment_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id'); // org HOLDING (sama dgn template.org_id)
            $table->uuid('template_id');

            $table->string('category');
            $table->string('subcategory')->nullable();

            // Kode pendek opsional (mis. "TK-FR-01") untuk referensi manusia.
            $table->string('question_code', 60)->nullable();
            $table->text('question');
            $table->text('explanation')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('regulation_ref')->nullable(); // mis. "Pasal 42-47"

            $table->decimal('weight', 5, 2)->default(1.0);
            $table->boolean('requires_evidence')->default(false);

            // yes_partial_no | yes_no | text | choice  (default mengikuti GAP)
            $table->string('answer_type', 30)->default('yes_partial_no');

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('holding_assessment_templates')->onDelete('cascade');
            $table->index(['template_id', 'is_active'], 'hold_assess_q_tpl_active_idx');
            $table->index(['org_id'], 'hold_assess_q_org_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_assessment_questions');
    }
};
