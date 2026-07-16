<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIA Assessment: two net-new columns.
 *  - description  = per-assessment free-text overview (plain text).
 *  - answer_notes = per-metric note map { metric_code: "note text" },
 *    parallel to the numeric metric scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('tia_assessments', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (! Schema::hasColumn('tia_assessments', 'answer_notes')) {
                $table->json('answer_notes')->nullable()->after('risk_assessment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('tia_assessments', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('tia_assessments', 'answer_notes')) {
                $table->dropColumn('answer_notes');
            }
        });
    }
};
