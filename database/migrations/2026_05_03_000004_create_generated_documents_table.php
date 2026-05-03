<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_documents — Document Maker outputs (Policy + Contract).
 *
 * Each row captures one AI-generated document together with the wizard
 * inputs that produced it and the structured AI output (sections JSON).
 * The `kind` column distinguishes Policy vs Contract; `document_type`
 * holds the granular template id (e.g. nda, msa, privacy_policy).
 *
 * Tenant-scoped via org_id (CLAUDE.md invariant). Soft-deletes for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('user_id');
            $t->string('kind', 16);                  // policy | contract
            $t->string('document_type', 64);         // e.g. nda, msa, privacy_policy
            $t->string('title', 255);
            $t->json('wizard_inputs');
            $t->json('ai_output');
            $t->string('ai_provider', 64)->nullable();
            $t->string('ai_model', 128)->nullable();
            $t->unsignedInteger('credits_used')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['org_id', 'kind']);
            $t->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
