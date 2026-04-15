<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint B3: Add attachments support to GAP assessments for compliance evidence
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('gap_assessments', 'attachments')) {
                // Stores { "questionId": ["path1", "path2"] }
                $table->json('attachments')->nullable()->after('answers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('gap_assessments', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
