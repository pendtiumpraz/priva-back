<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org overrides untuk pertanyaan DEFAULT Maturity Assessment.
 *
 * Pertanyaan default (maturity_questions, platform-level) bisa di-edit per
 * organisasi tanpa menyentuh bank aslinya — copy-on-write ala
 * gap_question_overrides. Kolom NULL = "tidak di-override, pakai nilai
 * default". Domain TIDAK bisa diubah (tetap mengikuti default).
 * is_active=false = pertanyaan default DINONAKTIFKAN untuk org ini
 * (tombstone, reversible — default tidak pernah bisa dihapus permanen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maturity_question_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            // question_code default dari maturity_questions (mis. GN-1, A1)
            $table->string('question_code', 16);
            $table->text('question_text')->nullable();
            $table->text('description')->nullable();
            $table->string('regulation_ref', 100)->nullable();
            $table->json('scoring_guide')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'maturity_q_override_org_q_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_question_overrides');
    }
};
