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
        if (Schema::hasColumn('tenant_themes', 'active_template_map')) {
            return;
        }
        Schema::table('tenant_themes', function (Blueprint $table) {
            $table->json('active_template_map')->nullable()->after('active_document_template_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tenant_themes', 'active_template_map')) {
            return;
        }
        Schema::table('tenant_themes', function (Blueprint $table) {
            $table->dropColumn('active_template_map');
        });
    }
};
