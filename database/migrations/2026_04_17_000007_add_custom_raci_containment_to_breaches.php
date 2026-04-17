<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint D4: Custom RACI + AI-generated dynamic containment steps on breach.
 * containment_checklist already exists (fixed 10-step default). This adds:
 *  - custom_raci: free-form RACI table per breach
 *  - containment_steps: dynamic AI-driven step list replacing the fixed 10
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('breach_incidents')) {
            return;
        }

        Schema::table('breach_incidents', function (Blueprint $table) {
            if (!Schema::hasColumn('breach_incidents', 'custom_raci')) {
                $table->json('custom_raci')->nullable();
            }
            if (!Schema::hasColumn('breach_incidents', 'containment_steps')) {
                $table->json('containment_steps')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('breach_incidents')) {
            return;
        }

        Schema::table('breach_incidents', function (Blueprint $table) {
            if (Schema::hasColumn('breach_incidents', 'custom_raci')) {
                $table->dropColumn('custom_raci');
            }
            if (Schema::hasColumn('breach_incidents', 'containment_steps')) {
                $table->dropColumn('containment_steps');
            }
        });
    }
};
