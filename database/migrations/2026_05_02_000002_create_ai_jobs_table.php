<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_jobs — async AI request ledger (INFRASTRUCTURE_PLAN.md §2.2).
 *
 * Every AI background job (autofill, analyzer, summary, deep_scan) gets a row
 * here. Multi-tenant scoped via org_id (CLAUDE.md invariant). Soft-deletes for
 * retention/audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('user_id')->index();
            $t->string('type', 32);                      // autofill|analyzer|summary|deep_scan
            $t->string('module', 32)->nullable();        // ropa|dpia|dsr|...
            $t->string('subject_id', 64)->nullable();    // ID record being processed
            $t->string('label', 191);                    // footer label text
            $t->string('status', 16)->default('pending')->index(); // pending|running|done|failed|cancelled
            $t->unsignedTinyInteger('progress')->default(0);
            $t->json('payload');
            $t->json('result')->nullable();
            $t->string('error', 1024)->nullable();
            $t->unsignedInteger('credits_used')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['org_id', 'status']);
            $t->index(['org_id', 'user_id', 'status']);
            // Note: cross-DB partial unique not portable. Dedup enforced at app layer
            // in AiJobController::store() (status IN pending|running). See §2.2 note.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
