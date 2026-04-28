<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit table for CRM extractor runs. Each row records a filter set that
 * produced an export (CSV/HubSpot/Salesforce/Mailchimp/webhook) and the
 * outcome — for compliance + cost tracking + retry-on-failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extract_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('initiated_by_user_id');
            $table->string('source', 32);                    // 'consent_logs' (future: 'cookie_logs' bulk)
            $table->json('filters');                         // {collection_id, purpose_keys[], date_from, ...}
            $table->string('output_target', 32);             // csv | hubspot | salesforce | mailchimp | webhook
            $table->string('output_target_ref', 200)->nullable(); // CRM list id, webhook url
            $table->integer('record_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->string('status', 20)->default('pending'); // pending|running|done|failed|partial
            $table->text('error_summary')->nullable();
            $table->json('result_meta')->nullable();          // export file path, sample rows, CRM response refs
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extract_runs');
    }
};
