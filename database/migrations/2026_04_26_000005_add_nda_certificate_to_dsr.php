<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NDA + Certificate fields untuk DSR compliance enhancement.
 *
 * dsr_apps:
 *   - requires_nda_for_access: tenant policy untuk access request
 *   - nda_template_doc_id: NDA template per app
 *   - nda_signing_method: e_signature | typed_acknowledgement | upload_signed_pdf
 *
 * dsr_requests:
 *   - nda_signed_at + nda_signed_doc_id: NDA execution evidence
 *   - subject_certificate_doc_id: PDF cert untuk subject (replaces completion_certificate_doc_id)
 *   - internal_certificate_doc_id: PDF cert untuk auditor/regulator (DPO-signed)
 */
return new class extends Migration {
    public function up(): void
    {
        // ===== dsr_apps =====
        if (Schema::hasTable('dsr_apps')) {
            $cols = [
                'requires_nda_for_access' => fn(Blueprint $t) => $t->boolean('requires_nda_for_access')->default(false)->after('webhook_url'),
                'nda_template_doc_id'     => fn(Blueprint $t) => $t->uuid('nda_template_doc_id')->nullable()->after('requires_nda_for_access'),
                'nda_signing_method'      => fn(Blueprint $t) => $t->string('nda_signing_method', 32)->default('e_signature')->after('nda_template_doc_id'),
            ];
            foreach ($cols as $name => $fn) {
                if (Schema::hasColumn('dsr_apps', $name)) continue;
                try {
                    Schema::table('dsr_apps', function (Blueprint $t) use ($fn) { $fn($t); });
                } catch (\Illuminate\Database\QueryException $e) {
                    $code = $e->errorInfo[1] ?? null;
                    if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                    throw $e;
                }
            }
        }

        // ===== dsr_requests =====
        if (Schema::hasTable('dsr_requests')) {
            $cols = [
                'nda_signed_at'                => fn(Blueprint $t) => $t->timestamp('nda_signed_at')->nullable()->after('verified_at'),
                'nda_signed_doc_id'            => fn(Blueprint $t) => $t->uuid('nda_signed_doc_id')->nullable()->after('nda_signed_at'),
                'subject_certificate_doc_id'   => fn(Blueprint $t) => $t->uuid('subject_certificate_doc_id')->nullable()->after('nda_signed_doc_id'),
                'internal_certificate_doc_id'  => fn(Blueprint $t) => $t->uuid('internal_certificate_doc_id')->nullable()->after('subject_certificate_doc_id'),
            ];
            foreach ($cols as $name => $fn) {
                if (Schema::hasColumn('dsr_requests', $name)) continue;
                try {
                    Schema::table('dsr_requests', function (Blueprint $t) use ($fn) { $fn($t); });
                } catch (\Illuminate\Database\QueryException $e) {
                    $code = $e->errorInfo[1] ?? null;
                    if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dsr_apps')) {
            foreach (['requires_nda_for_access', 'nda_template_doc_id', 'nda_signing_method'] as $col) {
                if (Schema::hasColumn('dsr_apps', $col)) {
                    try { Schema::table('dsr_apps', fn(Blueprint $t) => $t->dropColumn($col)); }
                    catch (\Throwable $e) {}
                }
            }
        }
        if (Schema::hasTable('dsr_requests')) {
            foreach (['nda_signed_at', 'nda_signed_doc_id', 'subject_certificate_doc_id', 'internal_certificate_doc_id'] as $col) {
                if (Schema::hasColumn('dsr_requests', $col)) {
                    try { Schema::table('dsr_requests', fn(Blueprint $t) => $t->dropColumn($col)); }
                    catch (\Throwable $e) {}
                }
            }
        }
    }
};
