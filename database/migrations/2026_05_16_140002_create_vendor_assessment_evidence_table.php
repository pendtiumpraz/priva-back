<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 2 — Per-question evidence storage.
 *
 * Sebelumnya bukti dokumen di-upload bulk di Step 1 wizard (akta notaris,
 * kontrak, company profile) — sifatnya identity-level, bukan per-pertanyaan.
 *
 * Tabel ini mencatat bukti yang di-upload pihak ketiga di setiap pertanyaan
 * yang `requires_evidence_upload=true`. Mis. pertanyaan "Apakah ada
 * kebijakan PDP terdokumentasi?" → vendor upload SOP_PDP_PT_Vendor.pdf.
 *
 * Storage: pakai TenantStorageService yang sudah ada — path:
 *   tenants/{org_id}/tprm/assessments/{assessment_id}/evidence/{question_id}/{filename}
 *
 * Tidak overwrite — kalau vendor upload ulang, file baru ditambah dengan
 * is_active=true sementara yang lama jadi is_active=false. Reviewer/Approver
 * bisa lihat history.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_assessment_evidence')) return;
        Schema::create('vendor_assessment_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('assessment_id')->index();
            $table->uuid('question_id')->index();    // FK ke vendor_questionnaires.id

            $table->string('file_path');             // path relative ke disk
            $table->string('original_name');         // nama file dari user
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            // Siapa yang upload: kalau dari token public, uploaded_by_user_id NULL
            // dan uploaded_by_token=true; kalau dari admin tenant, simpan user_id.
            $table->uuid('uploaded_by_user_id')->nullable();
            $table->boolean('uploaded_by_token')->default(false);
            $table->string('uploaded_ip', 45)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assessment_id', 'question_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_assessment_evidence');
    }
};
