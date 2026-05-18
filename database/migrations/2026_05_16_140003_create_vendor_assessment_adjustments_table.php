<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 2 — Reviewer adjustment audit log.
 *
 * Setiap kali Reviewer mengubah jawaban yang sudah di-submit pihak ketiga,
 * row baru ditulis di sini untuk audit trail. Approver bisa lihat siapa
 * mengubah apa, kapan, kenapa.
 *
 * Tujuan utama: defensible audit dalam audit eksternal BUMN — kalau ditanya
 * "kenapa skor vendor X turun setelah review padahal vendor jawab Ya semua?",
 * row ini menjawabnya.
 *
 * Format value: ke-2 kolom (before/after) flexible (JSON-encoded string)
 * supaya bisa simpan struktur jawaban kompleks (multi_choice array, dll).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_assessment_adjustments')) return;
        Schema::create('vendor_assessment_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('assessment_id')->index();
            $table->uuid('question_id')->index();      // FK ke vendor_questionnaires.id

            // Aksi: 'override' (ganti jawaban), 'comment' (cuma tambah catatan),
            // 'evidence_review' (review attachment), 'restore' (revert override)
            $table->string('action', 32)->default('override');

            $table->text('before_value')->nullable();    // JSON string jawaban lama
            $table->text('before_note')->nullable();
            $table->text('after_value')->nullable();     // JSON string jawaban baru
            $table->text('after_note')->nullable();

            $table->text('reason')->nullable();           // alasan reviewer ubah

            $table->uuid('adjusted_by_user_id');         // wajib (audit)
            $table->string('adjusted_by_role', 32)->nullable(); // 'reviewer' | 'approver' | dst
            $table->timestamp('adjusted_at');

            $table->timestamps();

            $table->index(['assessment_id', 'adjusted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_assessment_adjustments');
    }
};
