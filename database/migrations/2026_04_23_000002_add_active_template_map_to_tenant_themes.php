<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H1 — per-document-kind template assignment.
 *
 * Stored shape:
 *   {
 *     "default":         "uuid",   // fallback for any kind not mapped
 *     "ropa":            "uuid",
 *     "dpia":            "uuid",
 *     "gap_report":      "uuid",
 *     "breach_report":   "uuid",
 *     "breach_komdigi":  "uuid",
 *     "breach_subject":  "uuid",
 *     "posture":         "uuid"
 *   }
 *
 * `active_document_template_id` (single-template assignment) stays on the
 * table for back-compat — lookup treats it as the "default" entry when
 * `active_template_map` is null/empty.
 */
return new class extends Migration {
    public function up(): void
    {
        // Belt-and-suspenders idempotency. Schema::hasColumn has returned
        // false on production servers where the column actually exists
        // (schema cache staleness after a prior partial migration), so we
        // also catch the DB-level "Duplicate column" error as a safety net.
        if (Schema::hasColumn('tenant_themes', 'active_template_map')) {
            return;
        }
        try {
            Schema::table('tenant_themes', function (Blueprint $table) {
                $table->json('active_template_map')->nullable()->after('active_document_template_id');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL 1060 / PostgreSQL 42701 = "column already exists".
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) {
                return; // Column is there, nothing to do.
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tenant_themes', 'active_template_map')) {
            return;
        }
        try {
            Schema::table('tenant_themes', function (Blueprint $table) {
                $table->dropColumn('active_template_map');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Column not found on rollback = already gone. Safe to ignore.
        }
    }
};
