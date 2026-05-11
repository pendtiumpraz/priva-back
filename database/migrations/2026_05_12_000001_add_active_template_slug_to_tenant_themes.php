<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H2 — pivot ke client-side PDF/DOCX rendering.
 *
 * Sebelumnya pilihan template tersimpan sebagai UUID di
 * `active_document_template_id` (single) atau `active_template_map`
 * (per-kind). Pada pivot ini, template di-render sepenuhnya di sisi
 * client memakai @react-pdf/renderer; identifier-nya adalah slug
 * (`midnight-indigo`, `editorial-classic`, dll) yang merujuk ke file
 * React di `frontend/src/lib/pdf/templates/<slug>.tsx`.
 *
 * Kolom ini menyimpan slug aktif per tenant supaya pilihan user
 * tetap konsisten lintas perangkat dan sesi (tidak hanya
 * localStorage). Kolom legacy tetap dipertahankan untuk
 * back-compat dengan kode lama yang masih membaca UUID.
 */
return new class extends Migration {
    public function up(): void
    {
        // Idempotency belt-and-suspenders mengikuti pola migration
        // active_template_map sebelumnya — beberapa lingkungan produksi
        // memiliki cache schema yang stale sehingga Schema::hasColumn
        // bisa keliru. Tangkap juga error duplicate column dari DB.
        if (Schema::hasColumn('tenant_themes', 'active_template_slug')) {
            return;
        }
        try {
            Schema::table('tenant_themes', function (Blueprint $table) {
                $table->string('active_template_slug', 64)
                    ->nullable()
                    ->after('active_template_map');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            // MySQL 1060 / PostgreSQL 42701 / SQLSTATE 42S21 =
            // "column already exists" — aman untuk diabaikan.
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) {
                return;
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tenant_themes', 'active_template_slug')) {
            return;
        }
        try {
            Schema::table('tenant_themes', function (Blueprint $table) {
                $table->dropColumn('active_template_slug');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Kolom sudah tidak ada saat rollback — aman.
        }
    }
};
