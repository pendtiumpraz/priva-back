<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 4 — Incident reports terhadap pihak ketiga.
 *
 * Internal user report bahwa vendor X melanggar SLA / bocor data / tidak
 * comply ke kontrak / dll. Incident terhubung ke vendor + bisa di-link ke
 * monitoring review berikutnya untuk dipertimbangkan saat re-review.
 *
 * Kind enum:
 *   - sla_breach          (SLA tidak terpenuhi)
 *   - data_breach         (kebocoran data subjek)
 *   - contract_violation  (pelanggaran klausa kontrak)
 *   - compliance_failure  (gagal audit / sertifikasi)
 *   - service_outage      (downtime / unavailability)
 *   - financial_default   (gagal bayar / pailit)
 *   - reputation_event    (negative news / scandal)
 *   - other
 *
 * Severity: low | medium | high | critical (mirror VendorScreening)
 *
 * Status: open | investigating | mitigated | resolved | escalated
 *
 * impact_score_delta: integer (positif untuk menaikkan risk_score vendor;
 * default 0 — admin yang putuskan apply atau tidak).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_incidents')) return;
        Schema::create('vendor_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('vendor_id')->index();
            $table->uuid('reporter_user_id');

            $table->string('kind', 32)->index();
            $table->string('severity', 16)->index();    // low|medium|high|critical
            $table->string('title', 200);
            $table->text('description');

            $table->timestamp('occurred_at')->nullable();   // kapan kejadian sebenarnya
            $table->timestamp('detected_at')->nullable();   // kapan tim sadar
            $table->timestamp('resolved_at')->nullable();

            $table->string('status', 24)->default('open')->index();
            $table->text('resolution_note')->nullable();
            $table->uuid('resolved_by')->nullable();

            // Evidence files attached: JSON array
            //   [{ path, original_name, mime_type, size, uploaded_at }]
            $table->jsonb('evidence_files')->nullable();

            // Dampak ke risk vendor — opsional, admin yang trigger apply
            $table->integer('impact_score_delta')->default(0);
            $table->boolean('applied_to_risk_score')->default(false);

            // Link ke entity terkait (optional)
            $table->uuid('related_screening_id')->nullable();  // kalau insiden ditemukan via screening
            $table->uuid('related_review_id')->nullable();      // kalau ditemukan di monitoring review

            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'status', 'severity']);
            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_incidents');
    }
};
