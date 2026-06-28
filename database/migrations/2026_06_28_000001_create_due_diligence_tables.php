<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due Diligence Center (root-only / platform-level).
 *
 * Menyimpan kuesioner Technical Due Diligence (TDD) + daftar dokumen yang
 * diminta, lengkap dengan jawaban rekomendasi yang BISA DIEDIT oleh root.
 * Dipakai untuk menyiapkan jawaban TDD (mis. proses akuisisi) + export PDF
 * ter-style dari frontend.
 *
 * Platform-level: tidak di-scope ke org_id (satu set kanonik milik root).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 39 pertanyaan TDD + jawaban rekomendasi (editable).
        Schema::create('due_diligence_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('q_no')->index();
            $table->string('area')->nullable();          // mis. "Pembuka", "AI / Keamanan Data"
            $table->string('sub_topic')->nullable();     // mis. "Dua Produk & Codebase"
            $table->string('qtype', 16)->default('TUL'); // DEMO | DOK | TUL
            $table->text('question');                    // teks pertanyaan / instruksi demo
            $table->text('recommended_answer')->nullable(); // jawaban rekomendasi (EDITABLE)
            $table->string('evidence')->nullable();      // dokumen/bukti pendukung (EDITABLE)
            $table->string('status', 16)->default('perlu_kerja'); // siap | perlu_kerja | landmine (RAG)
            $table->text('internal_note')->nullable();   // catatan internal (EDITABLE)
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // 14 dokumen yang diminta, tiap dokumen punya tabel detail (editable).
        Schema::create('due_diligence_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('doc_no')->index();
            $table->string('category')->nullable();      // Produk | Arsitektur | IP & Legal | ...
            $table->string('name');                      // nama dokumen yang diminta
            $table->text('request_text')->nullable();    // teks request lengkap
            $table->string('priority', 16)->default('SEDANG'); // TINGGI | SEDANG | RENDAH
            $table->string('format', 16)->default('table');    // table | file
            $table->string('doc_status', 24)->default('draft'); // draft | disiapkan | terkirim
            $table->date('received_date')->nullable();   // tanggal terima (EDITABLE)
            $table->text('guidance')->nullable();        // instruksi pengisian templat
            $table->text('recommendation')->nullable();  // catatan rekomendasi dariku (EDITABLE)
            $table->json('columns')->nullable();         // header kolom tabel (EDITABLE)
            $table->json('rows')->nullable();            // baris isian detail (EDITABLE)
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('due_diligence_documents');
        Schema::dropIfExists('due_diligence_questions');
    }
};
