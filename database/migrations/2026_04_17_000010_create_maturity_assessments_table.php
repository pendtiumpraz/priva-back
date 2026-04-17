<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint F3: Maturity Level Assessment — 5-level CMM style per dimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maturity_assessments')) {
            return;
        }

        try {
            Schema::create('maturity_assessments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('title');
                $table->string('version', 32)->default('v1');
                $table->json('dimensions')->nullable();
                $table->unsignedTinyInteger('overall_level')->default(1);
                $table->decimal('overall_score', 5, 2)->default(0);
                $table->json('recommendations')->nullable();
                $table->string('status', 32)->default('draft');
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->index(['org_id', 'status'], 'maturity_org_status_idx');
            });
        } catch (\Throwable $e) {
            if (!$this->alreadyExists($e)) throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_assessments');
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
