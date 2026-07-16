<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP Assessment: catatan/jawaban teks per-soal (selain pilihan yes/partial/no/na).
 * Disimpan sebagai JSON map { question_id: "teks jawaban" } — sejajar `answers`.
 * `description` per-asesmen sudah ada (create_privasimu_tables), jadi tak dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('gap_assessments', 'answer_notes')) {
                $table->json('answer_notes')->nullable()->after('answers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('gap_assessments', 'answer_notes')) {
                $table->dropColumn('answer_notes');
            }
        });
    }
};
