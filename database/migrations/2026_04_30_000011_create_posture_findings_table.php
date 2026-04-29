<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3b — Posture findings.
 *
 * Materialization of "what's wrong with my posture" into actionable
 * tickets with severity, owner, SLA, status. Replaces the per-pillar
 * "reason" text (which is read-only) with a workable queue.
 *
 * Sources (source_pillar identifies which engine pillar generated it):
 *   - sensitive_protection  : PII column without protection_assessment
 *   - schema_drift          : unresolved diff_alert from scan
 *   - classification_coverage: PII column without any assessment
 *   - dpia_compliance       : HIGH-risk RoPA without approved DPIA
 *   - rtp_hygiene           : overdue RTP item
 *   - vendor_risk           : vendor overdue for re-assessment
 *   - cross_border_basis    : transfer with legal_basis = none
 *   - breach_readiness      : breach not notified within 72h
 *   - dsr_compliance        : DSR closed past deadline
 *
 * Each finding has a stable `source_key` so re-running materialization
 * doesn't duplicate — if the same problem still exists, the existing
 * finding is updated (last_seen_at), not duplicated.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('posture_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // What pillar surfaced this finding
            $table->string('source_pillar');

            // Stable dedup key (e.g. "sensitive_protection:sysid:tablename.colname")
            $table->string('source_key', 500);

            // Polymorphic-ish reference to the underlying record
            $table->string('source_type')->nullable();    // 'information_system' | 'ropa' | 'breach_incident' | 'vendor' | 'cross_border_transfer' | 'dsr_request' | 'dpia'
            $table->uuid('source_id')->nullable();
            $table->string('source_detail')->nullable();  // free-form narrative for FE display (e.g. "users.nik")

            // Classification
            $table->string('severity');                   // critical | high | medium | low
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('regulation_ref')->nullable(); // 'UU PDP Pasal 4', 'POJK 11/2022', etc.
            $table->jsonb('metadata')->nullable();

            // Workflow
            $table->string('status')->default('open');    // open | in_progress | resolved | accepted_risk | dismissed
            $table->uuid('assigned_to')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['org_id', 'source_key']);
            $table->index(['org_id', 'status', 'severity']);
            $table->index(['org_id', 'source_pillar']);
            $table->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posture_findings');
    }
};
