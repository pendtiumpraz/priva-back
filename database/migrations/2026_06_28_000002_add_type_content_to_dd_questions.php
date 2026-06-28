<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konten per-tipe untuk pertanyaan Due Diligence:
 *  - DEMO → demo_steps (alur langkah) + documentation (dokumentasi pendukung)
 *  - DOK  → doc_ref (nomor dokumen detail di due_diligence_documents)
 *  - TUL  → recommended_answer (sudah ada; diisi jawaban tertulis retoris)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('due_diligence_questions', function (Blueprint $table) {
            $table->json('demo_steps')->nullable()->after('recommended_answer');
            $table->text('documentation')->nullable()->after('demo_steps');
            $table->integer('doc_ref')->nullable()->after('documentation'); // -> due_diligence_documents.doc_no
        });
    }

    public function down(): void
    {
        Schema::table('due_diligence_questions', function (Blueprint $table) {
            $table->dropColumn(['demo_steps', 'documentation', 'doc_ref']);
        });
    }
};
