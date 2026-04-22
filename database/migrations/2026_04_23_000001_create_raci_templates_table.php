<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RACI template library (Phase G1) — per-tenant reusable library.
 *
 * Stored shape (matrix JSON):
 *   {
 *     "isolation":     {"responsible": "it-security", "accountable": "ciso",
 *                       "consulted": ["dpo"],       "informed": ["direksi"]},
 *     "forensics":     {...},
 *     ...
 *   }
 *
 * Apply flow: POST /breach/{id}/apply-raci-template writes each step's raci
 * with the template entry that matches step.category. Soft-deleted so in-use
 * templates can be restored; in-use count checked against BreachIncident
 * before hard delete (same pattern as ContainmentTemplate).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('raci_templates')) {
            return;
        }
        Schema::create('raci_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index();          // null = system default
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->json('matrix');                               // {category: RACIEntry}
            $table->boolean('is_system')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raci_templates');
    }
};
