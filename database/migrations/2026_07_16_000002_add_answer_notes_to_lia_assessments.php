<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LIA Assessment: per-question free-text note map.
 * Stored as a JSON object { question_code: "note text" } — one entry per
 * effective wizard question, independent of the structured test answers.
 * The per-assessment `description` already exists (create_privasimu_tables),
 * so it is not created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('lia_assessments', 'answer_notes')) {
                $table->json('answer_notes')->nullable()->after('balancing_test');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('lia_assessments', 'answer_notes')) {
                $table->dropColumn('answer_notes');
            }
        });
    }
};
