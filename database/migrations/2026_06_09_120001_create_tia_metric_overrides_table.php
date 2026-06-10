<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org overrides untuk metrik DEFAULT TIA (Transfer Impact Assessment).
 *
 * Metrik default (TiaAssessment::DEFAULT_METRICS, platform-level const)
 * bisa di-edit per organisasi tanpa menyentuh katalognya — copy-on-write
 * ala gap_question_overrides / maturity_question_overrides. Kolom NULL =
 * "tidak di-override, pakai nilai default". Kind (risk|security) TIDAK
 * bisa diubah (tetap mengikuti katalog default).
 * is_active=false = metrik default DINONAKTIFKAN untuk org ini
 * (tombstone, reversible — default tidak pernah bisa dihapus permanen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tia_metric_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            // metric_code dari TiaAssessment::DEFAULT_METRICS
            // (mis. risk_regulation_mismatch, security_protocol_score)
            $table->string('metric_code', 64);
            $table->string('label', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 2)->nullable();   // NULL = bobot default (1)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'metric_code'], 'tia_metric_override_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tia_metric_overrides');
    }
};
