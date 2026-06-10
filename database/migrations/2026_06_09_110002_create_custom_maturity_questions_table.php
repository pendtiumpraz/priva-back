<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Maturity Assessment Questions per organisasi.
 *
 * Org bisa menambah pertanyaan sendiri di atas 18 pertanyaan default —
 * question_code auto-generated (CUST-1, CUST-2, ...), domain harus salah
 * satu dari 4 domain platform supaya skor custom ikut masuk ke rata-rata
 * domain yang dipilih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_maturity_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('question_code', 16);              // CUST-1, CUST-2, ...
            $table->string('domain', 40);                     // salah satu MaturityQuestion::ALL_DOMAINS
            $table->text('question_text');
            $table->text('description')->nullable();
            $table->string('regulation_ref', 100)->nullable();
            $table->json('scoring_guide')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'question_code'], 'custom_maturity_q_org_code_unique');
            $table->index(['org_id', 'is_active'], 'custom_maturity_q_org_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_maturity_questions');
    }
};
