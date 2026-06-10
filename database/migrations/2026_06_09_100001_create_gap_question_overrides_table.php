<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org overrides untuk pertanyaan DEFAULT GAP Assessment.
 *
 * Pertanyaan default (question bank di GapAssessment::getQuestionBank)
 * bisa di-edit per organisasi tanpa menyentuh bank aslinya — copy-on-write
 * ala TPRM question overrides. Kolom NULL = "tidak di-override, pakai nilai
 * default". is_active=false = pertanyaan default DINONAKTIFKAN untuk org ini
 * (tombstone, reversible — default tidak pernah bisa dihapus permanen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gap_question_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('regulation_code', 20)->default('uupdp');
            // ID pertanyaan default dari question bank (mis. TK-FR-01)
            $table->string('question_id', 64);
            $table->text('question')->nullable();
            $table->text('explanation')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->string('article')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'regulation_code', 'question_id'], 'gap_q_override_org_reg_q_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gap_question_overrides');
    }
};
