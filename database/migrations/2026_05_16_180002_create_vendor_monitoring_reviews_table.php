<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 4 — History review berkala (append-only).
 *
 * 1 monitoring schedule punya N review (1 per periode). Setiap review = 1 row.
 *
 * Checklist state JSONB:
 *   {
 *     "compliance_status": "comply" | "partial" | "non_comply",
 *     "incident_review": "clean" | "minor_issue" | "major_issue",
 *     "performance_sla": "met" | "below_threshold" | "critical_fail",
 *     "document_validity": "valid" | "expiring_soon" | "expired",
 *     "additional_checks": { ...custom items... }
 *   }
 *
 * Decision enum:
 *   - continue          (lanjutkan tanpa catatan)
 *   - continue_with_note (lanjut tapi ada catatan perbaikan)
 *   - improvement_required (vendor harus action item dalam timeline tertentu)
 *   - terminate          (rekomendasi putus kontrak)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_monitoring_reviews')) return;
        Schema::create('vendor_monitoring_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('monitoring_id')->index();
            $table->uuid('vendor_id')->index();

            $table->uuid('reviewer_user_id');           // wajib (audit)
            $table->timestamp('reviewed_at');

            $table->jsonb('checklist_state')->nullable();   // struktur fleksibel
            $table->string('decision', 32);                 // enum di atas
            $table->text('notes')->nullable();
            $table->text('action_items')->nullable();       // free-text saran perbaikan

            // Optional: link ke incidents yang relevan (JSON array of UUIDs)
            $table->jsonb('related_incident_ids')->nullable();

            // Snapshot risk pada saat review (bisa beda dari current vendor.risk_score
            // karena vendor mungkin re-assessed sejak review terakhir)
            $table->string('risk_level_snapshot', 16)->nullable();
            $table->unsignedTinyInteger('risk_score_snapshot')->nullable();

            $table->timestamps();
            // No soft-delete: append-only audit

            $table->index(['vendor_id', 'reviewed_at']);
            $table->index(['monitoring_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_monitoring_reviews');
    }
};
