<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org overrides untuk pertanyaan panduan DEFAULT wizard LIA.
 *
 * Pertanyaan default (LiaAssessment::DEFAULT_QUESTIONS, platform-level
 * const) bisa di-edit per organisasi tanpa menyentuh katalognya —
 * copy-on-write ala gap/maturity_question_overrides / tia_metric_overrides.
 * Kolom NULL = "tidak di-override, pakai nilai default". Test
 * (purpose|necessity|balancing) TIDAK bisa diubah (tetap mengikuti
 * katalog default). is_active=false = pertanyaan default DINONAKTIFKAN
 * untuk org ini (tombstone, reversible — default tidak pernah bisa
 * dihapus permanen).
 *
 * LIA adalah modul KUALITATIF — tidak ada scoring, jadi tidak ada kolom
 * weight. Hanya teks pertanyaan + deskripsi/bantuan yang bisa diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lia_question_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            // question_code dari LiaAssessment::DEFAULT_QUESTIONS
            // (mis. why_needed, is_necessary, subject_loses_control)
            $table->string('question_code', 64);
            $table->string('label', 500)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'lia_question_override_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lia_question_overrides');
    }
};
