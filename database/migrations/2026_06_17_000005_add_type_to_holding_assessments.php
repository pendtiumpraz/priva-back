<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding Assessment — dua jenis penilaian:
 *   - 'normal'   : gaya GAP (jawaban ya/sebagian/tidak, skor berbobot).
 *   - 'maturity' : gaya Maturity Level (jawaban skala 1-5, skor = rata-rata level).
 *
 * `type` di template = pilihan author; di-snapshot ke instance saat dispatch.
 * `maturity_level` (1-5) hanya terisi untuk instance bertipe maturity; skor %
 * (overall_score) tetap dihitung utk keduanya supaya grafik kepatuhan seragam.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('holding_assessment_templates')) {
            Schema::table('holding_assessment_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('holding_assessment_templates', 'type')) {
                    $table->string('type', 20)->default('normal')->after('regulation_name');
                }
            });
        }

        if (Schema::hasTable('holding_assessment_instances')) {
            Schema::table('holding_assessment_instances', function (Blueprint $table) {
                if (! Schema::hasColumn('holding_assessment_instances', 'type')) {
                    $table->string('type', 20)->default('normal')->after('regulation_name');
                }
                if (! Schema::hasColumn('holding_assessment_instances', 'maturity_level')) {
                    $table->unsignedTinyInteger('maturity_level')->nullable()->after('compliance_level');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('holding_assessment_templates')) {
            Schema::table('holding_assessment_templates', function (Blueprint $table) {
                if (Schema::hasColumn('holding_assessment_templates', 'type')) {
                    $table->dropColumn('type');
                }
            });
        }
        if (Schema::hasTable('holding_assessment_instances')) {
            Schema::table('holding_assessment_instances', function (Blueprint $table) {
                foreach (['type', 'maturity_level'] as $col) {
                    if (Schema::hasColumn('holding_assessment_instances', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
