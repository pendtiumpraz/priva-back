<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant knowledge base: allow each tenant to add their own KB sections.
 * org_id = null means shared/system-wide section.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_base_sections')) {
            return;
        }

        Schema::table('knowledge_base_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_base_sections', 'org_id')) {
                $table->uuid('org_id')->nullable()->index();
            }
        });

        // Drop the unique constraint on module_key because two tenants can both have
        // a section with module_key = 'ropa'. Replace with composite unique (org_id, module_key).
        try {
            Schema::table('knowledge_base_sections', function (Blueprint $table) {
                $table->dropUnique('knowledge_base_sections_module_key_unique');
            });
        } catch (\Throwable $e) {
            // Index name may differ or may not exist yet — ignore.
        }

        Schema::table('knowledge_base_sections', function (Blueprint $table) {
            // Composite unique so (null, 'ropa') and (tenantA, 'ropa') can co-exist.
            try {
                $table->unique(['org_id', 'module_key'], 'kb_org_module_unique');
            } catch (\Throwable $e) {
                // Already present from a previous run.
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_base_sections')) {
            return;
        }
        Schema::table('knowledge_base_sections', function (Blueprint $table) {
            try { $table->dropUnique('kb_org_module_unique'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('knowledge_base_sections', 'org_id')) {
                $table->dropColumn('org_id');
            }
            try { $table->unique('module_key'); } catch (\Throwable $e) {}
        });
    }
};
