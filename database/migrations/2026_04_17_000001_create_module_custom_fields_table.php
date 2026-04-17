<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C1: Per-tenant custom fields for ROPA / DPIA wizards.
 * Rendered dynamically after the last wizard section. Values stored at
 * wizard_data.custom_fields[{field_name}] on the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_custom_fields')) {
            return;
        }

        try {
            Schema::create('module_custom_fields', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('module', 32);
                $table->string('section_key', 64)->default('custom');
                $table->string('field_name', 64);
                $table->string('field_label');
                $table->string('field_type', 24);
                $table->json('field_options')->nullable();
                $table->text('help_text')->nullable();
                $table->boolean('is_required')->default(false);
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->unique(['org_id', 'module', 'field_name'], 'module_custom_fields_unique');
                $table->index(['org_id', 'module', 'is_active'], 'module_custom_fields_org_mod_idx');
            });
        } catch (\Throwable $e) {
            if (!$this->alreadyExists($e)) throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_custom_fields');
    }

    private function alreadyExists(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'already exists')
            || str_contains($msg, '1050')   // MySQL
            || str_contains($msg, '42S01')  // MySQL SQLSTATE
            || str_contains($msg, '42P07'); // PostgreSQL SQLSTATE
    }
};
