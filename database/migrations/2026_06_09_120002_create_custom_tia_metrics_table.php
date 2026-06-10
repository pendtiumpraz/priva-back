<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom TIA metrics per organisasi.
 *
 * Org bisa menambah metrik penilaian sendiri di atas 6 risk + 2 security
 * metrik default — metric_code auto-generated (CUST-1, CUST-2, ...),
 * kind harus 'risk' (skor 10 = paling berisiko) atau 'security' (skor
 * 10 = paling aman) supaya skor custom ikut masuk komponen weighted
 * average yang tepat di TiaAssessment::computeOverallRisk().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_tia_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('metric_code', 16);            // CUST-1, CUST-2, ...
            $table->string('kind', 16);                   // 'risk' | 'security'
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 2)->default(1);  // 1-10
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'metric_code'], 'custom_tia_metric_org_code_unique');
            $table->index(['org_id', 'is_active'], 'custom_tia_metric_org_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_tia_metrics');
    }
};
