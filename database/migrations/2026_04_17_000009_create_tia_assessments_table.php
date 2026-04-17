<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint F2: Transfer Impact Assessment — cross-border risk evaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tia_assessments')) {
            return;
        }

        try {
            Schema::create('tia_assessments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('title');
                $table->uuid('linked_cross_border_id')->nullable();
                $table->json('transfer_details')->nullable();
                $table->json('legal_framework')->nullable();
                $table->json('risk_assessment')->nullable();
                $table->json('supplementary_measures')->nullable();
                $table->string('overall_risk_level', 16)->default('medium');
                $table->string('status', 32)->default('draft');
                $table->json('wizard_data')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->index(['org_id', 'status'], 'tia_org_status_idx');
            });
        } catch (\Throwable $e) {
            if (!$this->alreadyExists($e)) throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tia_assessments');
    }

    private function alreadyExists(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'already exists')
            || str_contains($msg, '1050')
            || str_contains($msg, '42S01')
            || str_contains($msg, '42P07');
    }
};
