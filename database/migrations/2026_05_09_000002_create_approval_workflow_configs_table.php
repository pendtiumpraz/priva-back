<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi approval workflow per (org, module).
 *
 * `steps` = JSON array of:
 *   [
 *     { "tenant_role_id": "uuid", "label": "Compliance Lead", "conditional": null },
 *     { "tenant_role_id": "uuid", "label": "DPO" },
 *     { "tenant_role_id": "uuid", "label": "CEO" }
 *   ]
 *
 * `enabled` = false → submit langsung approved tanpa workflow.
 *
 * Module yang didukung saat ini: ropa, dpia.
 * (LIA/TIA pakai mekanisme sendiri, breach/dsr belum punya workflow.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflow_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('module', 32);
            $table->boolean('enabled')->default(true);
            $table->json('steps')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['org_id', 'module'], 'awc_org_module_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_configs');
    }
};
