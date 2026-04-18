<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend menu_items with parent_menu_id so nested items (Settings tabs,
 * sub-pages) share the same 3-layer visibility infrastructure as top-level.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) return;
        if (Schema::hasColumn('menu_items', 'parent_menu_id')) return;

        Schema::table('menu_items', function (Blueprint $table) {
            $table->uuid('parent_menu_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_items') && Schema::hasColumn('menu_items', 'parent_menu_id')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->dropColumn('parent_menu_id');
            });
        }
    }
};
