<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('feature_type'); // gap_remediation, ropa_analysis, dpia_risk_scoring, breach_advisor, dsr_draft, consent_generator, dashboard_summary, drill_scenario
            $table->uuid('record_id')->nullable(); // ID of the related record (gap assessment, ropa, dpia, etc.)
            $table->string('record_type')->nullable(); // Model class
            $table->json('input_data')->nullable(); // What was sent to AI
            $table->json('result_data'); // AI response (structured JSON)
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable(); // USD cost estimate
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'feature_type']);
            $table->index(['record_id', 'feature_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_results');
    }
};
