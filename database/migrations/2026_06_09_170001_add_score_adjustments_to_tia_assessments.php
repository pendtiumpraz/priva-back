<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Score adjustment with provenance untuk TIA — Checker/Approver bisa
 * menyesuaikan skor metrik (naik ATAU turun) selama review window
 * (status submitted/checked), dengan keterangan WAJIB.
 *
 * Shape (append-only array, latest entry per metric_code menang untuk
 * display):
 *   score_adjustments: [{ metric_code, old_score, new_score, reason,
 *                         adjusted_by, adjusted_by_name, adjusted_by_role,
 *                         adjusted_at }]
 *
 * Catatan: penyesuaian mengubah INPUT skor metrik — overall_risk_score
 * tetap dihitung computeOverallRisk() dari input (formula tidak berubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('tia_assessments', 'score_adjustments')) {
                $table->json('score_adjustments')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('tia_assessments', 'score_adjustments')) {
                $table->dropColumn('score_adjustments');
            }
        });
    }
};
