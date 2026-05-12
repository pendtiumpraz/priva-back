<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G — Customisasi pertanyaan per-tenant. Tenant bisa:
 *   1. Tambah pertanyaan custom (org_id = tenant, parent_id = null)
 *   2. Edit / nonaktifkan pertanyaan default (override row: org_id = tenant,
 *      parent_id = system_question_id, fields ke-override)
 *
 * Resolved list per tenant = MERGE override (kalau ada) + default (kalau
 * tidak di-override) + custom (parent_id null org-scoped).
 *
 * Plus tambah field 'recommendation_if_no' supaya rekomendasi muncul saat
 * pihak ketiga jawab "Tidak" — sesuai requirement Request Perubahan Modules.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_questionnaires', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->after('id')->index();
            $table->uuid('parent_id')->nullable()->after('org_id')->index();
            $table->text('recommendation_if_no')->nullable()->after('description');
            $table->boolean('requires_evidence_upload')->default(false)
                ->after('recommendation_if_no');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_questionnaires', function (Blueprint $table) {
            $table->dropColumn([
                'org_id', 'parent_id', 'recommendation_if_no', 'requires_evidence_upload',
            ]);
        });
    }
};
