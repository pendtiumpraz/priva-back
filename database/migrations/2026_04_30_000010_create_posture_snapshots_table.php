<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3a — Posture snapshots. Daily snapshot per org, persisted so
 * the trend chart shows REAL movement instead of `rand()` jitter.
 *
 * Three layer scores (data / process / response) + the 12-pillar
 * breakdown JSON so an auditor can drill into "why is my score
 * different from yesterday".
 *
 * No backfill — trust starts now. FE shows "trend builds from first
 * snapshot" until ≥ 7 data points exist.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('posture_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->timestamp('taken_at');

            // Layer scores (0-100)
            $table->tinyInteger('overall_score');
            $table->tinyInteger('layer_data_score');     // 50% weight in overall
            $table->tinyInteger('layer_process_score');  // 30%
            $table->tinyInteger('layer_response_score'); // 20%

            // Per-pillar drill-down — array of {
            //   pillar, layer, weight, score, raw_metrics, reason, delta_vs_prev
            // }
            $table->jsonb('pillar_breakdown')->nullable();

            // 'scheduled' (cron) | 'manual' (user clicked refresh) | 'event' (post-scan)
            $table->string('source')->default('scheduled');

            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'taken_at']);
            $table->index('taken_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posture_snapshots');
    }
};
