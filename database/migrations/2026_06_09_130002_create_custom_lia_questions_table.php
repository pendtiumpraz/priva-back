<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom LIA wizard questions per organisasi.
 *
 * Org bisa menambah pertanyaan panduan sendiri di atas katalog default —
 * question_code auto-generated (CUST-1, CUST-2, ...), test wajib
 * 'purpose' | 'necessity' | 'balancing' supaya pertanyaan muncul di step
 * wizard yang tepat. Jawaban custom disimpan free-text (textarea) di JSON
 * per-test record LIA (purpose_test / necessity_test / balancing_test),
 * keyed by question_code. TIDAK ada dampak scoring — verdict LIA tetap
 * diputuskan manual oleh Approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_lia_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('question_code', 16);          // CUST-1, CUST-2, ...
            $table->string('test', 16);                   // 'purpose' | 'necessity' | 'balancing'
            $table->string('label', 500);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'custom_lia_question_org_code_unique');
            $table->index(['org_id', 'is_active'], 'custom_lia_question_org_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_lia_questions');
    }
};
