<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * data_discovery_scan_plans — Person Scan plan ledger.
 *
 * One row per "Scan Person Across Apps" request. Stores masked identifiers
 * (so the plan record itself is not a PII risk) plus aggregated counts.
 * Multi-tenant scoped via org_id (CLAUDE.md invariant); soft-deletes preserved
 * for audit + retention.
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_discovery_scan_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('user_id')->index();
            $t->string('label', 191);
            $t->json('identifiers');                    // masked {email,name,nik?,phone?,dob?}
            $t->json('identifier_hashes')->nullable();  // sha256(value+org_id) for dedup/audit
            $t->string('status', 24)->default('generated')->index();
            //   generated|executing|awaiting_upload|completed|failed|expired
            $t->unsignedSmallInteger('total_systems')->default(0);
            $t->unsignedSmallInteger('total_tables')->default(0);
            $t->unsignedSmallInteger('skipped_tables')->default(0);
            $t->unsignedInteger('total_hits')->default(0);
            $t->unsignedTinyInteger('progress')->default(0); // 0-100, mirrors parent ai_job
            $t->uuid('parent_ai_job_id')->nullable()->index();
            $t->string('saas_pack_path', 500)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['org_id', 'status']);
            $t->index(['org_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_discovery_scan_plans');
    }
};
