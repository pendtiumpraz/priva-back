<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_policies — Policy Generator outputs (UU PDP privacy policies drafted
 * from wizard input). Sibling of generated_documents but a first-class feature:
 * carries `audience` (customer|employee|job_applicant|external), `language`,
 * `status` (draft|finalized) and an `ai_metadata` blob holding the 15-element
 * coverage map + per-clause RAG/source trail for the legal-safety audit.
 *
 * Tenant-scoped via org_id (CLAUDE.md invariant). Soft-deletes for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('created_by')->nullable();
            $t->string('audience', 32)->default('customer');     // customer | employee | job_applicant | external
            $t->string('language', 8)->default('id');            // id | en
            $t->string('document_type', 64)->default('privacy_policy');
            $t->string('status', 16)->default('draft');          // draft | finalized
            $t->string('title', 255);
            $t->json('wizard_inputs');
            $t->json('ai_output');                               // canonical sections JSON
            $t->json('ai_metadata')->nullable();                 // coverage map, clause source trail, model/provider
            $t->string('ai_provider', 64)->nullable();
            $t->string('ai_model', 128)->nullable();
            $t->unsignedInteger('credits_used')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['org_id', 'status']);
            $t->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_policies');
    }
};
