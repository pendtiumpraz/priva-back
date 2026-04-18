<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant lifecycle tracking for offboarding scenarios:
 *   - active      → normal operations
 *   - frozen      → read-only, kept for legal/compliance period (e.g. 7y)
 *   - transferred → company sold/renamed; same data, new ownership
 *   - archived    → marked for permanent delete after hard_delete_at
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organizations')) return;

        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'lifecycle_status')) {
                $table->string('lifecycle_status', 24)->default('active')->index();
            }
            if (!Schema::hasColumn('organizations', 'offboarded_at')) {
                $table->timestamp('offboarded_at')->nullable();
            }
            if (!Schema::hasColumn('organizations', 'offboarded_by')) {
                $table->uuid('offboarded_by')->nullable();
            }
            if (!Schema::hasColumn('organizations', 'offboard_reason')) {
                $table->string('offboard_reason', 64)->nullable(); // sold|merged|end_of_contract|bankrupt|other
            }
            if (!Schema::hasColumn('organizations', 'offboard_notes')) {
                $table->text('offboard_notes')->nullable();
            }
            if (!Schema::hasColumn('organizations', 'hard_delete_at')) {
                $table->timestamp('hard_delete_at')->nullable();
            }
            if (!Schema::hasColumn('organizations', 'transferred_from')) {
                $table->uuid('transferred_from')->nullable(); // if new org is a transfer of old
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('organizations')) return;
        Schema::table('organizations', function (Blueprint $table) {
            foreach (['lifecycle_status', 'offboarded_at', 'offboarded_by', 'offboard_reason', 'offboard_notes', 'hard_delete_at', 'transferred_from'] as $col) {
                if (Schema::hasColumn('organizations', $col)) $table->dropColumn($col);
            }
        });
    }
};
