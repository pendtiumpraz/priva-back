<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — Hubungkan assessment ke library yang dipakai.
 *
 * Setiap assessment terhubung ke 1 library pada saat dibuat. Public
 * assessment page render pertanyaan filter library_id (kalau ada),
 * fallback ke logic lama (effectiveForOrg + category) untuk legacy
 * assessment yang belum punya library_id.
 *
 * Nullable supaya backward-compat — assessment lama tidak rusak.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->uuid('library_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->dropColumn('library_id');
        });
    }
};
