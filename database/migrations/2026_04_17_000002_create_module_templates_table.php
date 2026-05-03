<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C1 Step 7: Per-tenant wizard templates for RoPA / DPIA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_templates')) {
            return;
        }

        try {
            Schema::create('module_templates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('module', 32);
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('template_data');
                $table->boolean('is_default')->default(false);
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->index(['org_id', 'module', 'is_default'], 'module_templates_org_mod_idx');
            });
        } catch (Throwable $e) {
            if (! $this->alreadyExists($e)) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_templates');
    }

    private function alreadyExists(Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'already exists')
            || str_contains($msg, '1050')
            || str_contains($msg, '42S01')
            || str_contains($msg, '42P07');
    }
};
