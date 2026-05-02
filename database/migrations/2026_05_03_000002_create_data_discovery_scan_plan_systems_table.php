<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * data_discovery_scan_plan_systems — per-app SQL bundle for a scan plan.
 *
 * One row per InformationSystem included in the plan. Holds the generated
 * SELECT statements (positional `?` placeholders) + matched columns + chosen
 * confidence per table. OnPrem child AiJob fans out one row at a time.
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_discovery_scan_plan_systems', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('scan_plan_id')->index();
            $t->uuid('information_system_id')->index();
            $t->string('app_name', 191);
            $t->json('table_queries');
            //   [{table, sql, params, confidence, matched_columns, primary_key, returned_columns}]
            $t->string('status', 16)->default('pending')->index();
            //   pending|running|done|failed|skipped
            $t->unsignedInteger('hit_count')->default(0);
            $t->uuid('child_ai_job_id')->nullable()->index();
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['org_id', 'scan_plan_id']);
            $t->index(['org_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_discovery_scan_plan_systems');
    }
};
