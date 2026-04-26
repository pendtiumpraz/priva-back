<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic documents table — stores generated certificates, uploaded evidence,
 * NDA signed copies, etc. Org-scoped via org_id.
 *
 * Storage path lives in storage_path field; actual bytes saved via
 * TenantStorageService::storeTenantPrivateFile().
 *
 * source_type + source_id is a polymorphic-light pointer back to the entity
 * that owns the document (e.g. dsr_request, dsr_execution, vendor).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('documents')) return;

        try {
            Schema::create('documents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('kind', 64);
                // dsr.subject_certificate | dsr.internal_certificate
                // dsr.execution_evidence | dsr.nda_signed
                // (extensible — keep namespace prefix per module)

                $table->string('source_type', 64)->nullable();
                $table->uuid('source_id')->nullable();

                $table->string('name', 255);
                $table->string('mime_type', 120)->nullable();
                $table->integer('size_bytes')->nullable();
                $table->string('storage_path', 512);
                $table->string('storage_driver', 32)->default('local');

                $table->uuid('uploaded_by')->nullable();
                $table->json('metadata')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->index(['org_id', 'kind']);
                $table->index(['source_type', 'source_id']);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1050 || in_array($e->getCode(), ['42P07', '42S01'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
