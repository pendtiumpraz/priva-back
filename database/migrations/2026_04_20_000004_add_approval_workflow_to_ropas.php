<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add DPO approval-workflow columns to ROPA. The existing `status` column
 * already carries draft / waiting / revision / approved — we just need the
 * metadata around who submitted / reviewed / rejected and when.
 *
 * State machine mapping:
 *   draft     → not yet submitted (default)
 *   waiting   → submitted by maker, pending DPO review
 *   revision  → DPO sent back with review_notes
 *   approved  → DPO approved
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            if (!Schema::hasColumn('ropas', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('ropas', 'submitted_by')) {
                $table->uuid('submitted_by')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('ropas', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('submitted_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            foreach (['submitted_at', 'submitted_by', 'review_notes'] as $col) {
                if (Schema::hasColumn('ropas', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
