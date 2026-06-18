<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding Compliance Assessment — Instances (dispatched assessments).
 *
 * Satu baris per (template → target org). Dimiliki org HOLDING (org_id) sehingga
 * reviewer holding membaca dalam scope org-nya sendiri tanpa cross-org gymnastics;
 * `target_org_id` mencatat anak perusahaan / sub-holding yang dinilai.
 *
 * Diisi via PUBLIC LINK (pola TPRM): assessment_token + token_expires_at +
 * token_consumed_at (single-use). Pertanyaan dibekukan (questions_snapshot) saat
 * dispatch supaya edit template kemudian tidak mengubah assessment yang sudah dikirim.
 *
 * answers & ai_analyses disimpan JSON (konsisten dgn GAP/TPRM). Bukti evidence
 * di tabel terpisah (holding_assessment_evidence, 1:N per pertanyaan).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('holding_assessment_instances')) {
            return;
        }
        Schema::create('holding_assessment_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id'); // org HOLDING pemilik campaign

            $table->uuid('template_id')->nullable();
            $table->uuid('source_org_id')->nullable();  // holding (= org_id; eksplisit utk audit)
            $table->uuid('target_org_id')->nullable();   // anak perusahaan / sub-holding yang dinilai
            $table->string('target_org_name')->nullable(); // snapshot nama

            $table->string('title');
            $table->string('regulation_code', 40)->nullable();
            $table->string('regulation_name')->nullable();

            // Snapshot pertanyaan saat dispatch (freeze). answers & ai_analyses JSON.
            $table->json('questions_snapshot')->nullable();
            $table->json('answers')->nullable();      // { "<qid>": { value, note } }
            $table->json('ai_analyses')->nullable();  // { "<qid>": [ AnalysisResult, ... ] }

            // pending | sent | in_progress | submitted | review_in_progress | approved | rejected
            $table->string('status', 30)->default('pending');

            $table->decimal('overall_score', 5, 2)->nullable();
            $table->string('compliance_level', 20)->nullable(); // low | medium | high
            $table->decimal('progress', 5, 2)->default(0);

            // --- Public token (pola TPRM) ---
            $table->uuid('assessment_token')->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_consumed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->string('submitted_user_agent')->nullable();

            // --- Reviewer (dari holding) ---
            $table->uuid('reviewer_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_status', 20)->nullable(); // approved | rejected | needs_revision
            $table->text('review_notes')->nullable();
            $table->json('review_data')->nullable(); // per-question verdict/catatan reviewer

            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('holding_assessment_templates')->onDelete('set null');
            $table->foreign('target_org_id')->references('id')->on('organizations')->onDelete('set null');

            $table->index(['org_id', 'status'], 'hold_assess_inst_org_status_idx');
            $table->index(['target_org_id'], 'hold_assess_inst_target_idx');
            $table->index(['template_id'], 'hold_assess_inst_tpl_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_assessment_instances');
    }
};
