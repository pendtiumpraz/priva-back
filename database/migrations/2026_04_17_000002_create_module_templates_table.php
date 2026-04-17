<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C1 Step 7: Per-tenant wizard templates for ROPA / DPIA.
 * Users save current wizard_data as a template; new records can start from one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('module', 32);          // ropa | dpia
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('template_data');         // snapshot of wizard_data
            $table->boolean('is_default')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'module', 'is_default'], 'module_templates_org_mod_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_templates');
    }
};
