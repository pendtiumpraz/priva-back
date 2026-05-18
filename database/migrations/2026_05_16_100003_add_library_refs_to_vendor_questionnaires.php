<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — Tautan pertanyaan existing ke library + segment.
 *
 * Nullable supaya backward-compat: pertanyaan lama yang belum di-link
 * tetap bisa diambil via category+version (kode lama). Backfill seeder
 * akan mengisi field ini untuk semua row PDP v2_2026 yang sudah ada.
 *
 * Tidak ada FK constraint (cross-tenant + soft-delete consideration);
 * integrity dijaga di application layer (model relation + tenant scope).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('vendor_questionnaires')) return;
        Schema::table('vendor_questionnaires', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_questionnaires', 'library_id')) {
                $table->uuid('library_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('vendor_questionnaires', 'library_segment_id')) {
                $table->uuid('library_segment_id')->nullable()->after('library_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_questionnaires', function (Blueprint $table) {
            $table->dropColumn(['library_id', 'library_segment_id']);
        });
    }
};
