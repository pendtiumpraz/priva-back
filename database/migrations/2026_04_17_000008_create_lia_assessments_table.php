<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint F1: Legitimate Interest Assessment — 3-phase test.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lia_assessments')) {
            return;
        }

        try {
            Schema::create('lia_assessments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('processing_activity');
                $table->uuid('linked_ropa_id')->nullable();
                $table->json('purpose_test')->nullable();
                $table->json('necessity_test')->nullable();
                $table->json('balancing_test')->nullable();
                $table->decimal('overall_score', 5, 2)->default(0);
                $table->string('assessment_result', 32)->default('draft');
                $table->string('status', 32)->default('draft');
                $table->json('wizard_data')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->index(['org_id', 'status'], 'lia_org_status_idx');
            });
        } catch (\Throwable $e) {
            if (!$this->alreadyExists($e)) throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lia_assessments');
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
