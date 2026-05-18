<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — Library segments / domains.
 *
 * 1 library punya N segment (= "domain" atau "section" pada UI). Mis. di
 * library PDP UU 27/2022 v2_2026:
 *   - Tata Kelola (10 pertanyaan, weight 25%)
 *   - Operasi (15 pertanyaan, weight 20%)
 *   - SDM (8 pertanyaan, weight 15%)
 *   - Teknologi & Keamanan (15 pertanyaan, weight 25%)
 *   - Data Handling (8 pertanyaan, weight 15%)
 *
 * weight_pct: bobot relatif segment terhadap total score (sum semua segment
 * dalam 1 library idealnya = 100). Per-pertanyaan tetap pakai field `weight`
 * di vendor_questionnaires untuk bobot relatif di dalam segment.
 *
 * Kalau library belum membagi segment, frontend bisa render flat (1 segment
 * default bernama "Umum"). Migration backfill akan create segments per
 * distinct `section` dari vendor_questionnaires existing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('question_library_segments')) return;
        Schema::create('question_library_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('library_id')->index();
            $table->string('name');                          // "Tata Kelola"
            $table->string('code')->nullable();              // "GOV" — short prefix code
            $table->text('description')->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->unsignedTinyInteger('weight_pct')->default(0); // 0-100
            $table->unsignedInteger('questions_count')->default(0); // counter cache
            $table->timestamps();
            $table->softDeletes();

            $table->index(['library_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_library_segments');
    }
};
