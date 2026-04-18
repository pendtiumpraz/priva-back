<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Relocate the Menu Preferences item out of /settings and into a top-level
     * route so its sidebar link no longer 404s and doesn't hijack the Settings
     * active-highlight.
     */
    public function up(): void
    {
        if (!\Schema::hasTable('menu_items')) return;

        DB::table('menu_items')
            ->where('menu_key', 'menu-preferences')
            ->update(['href' => '/menu-preferences']);
    }

    public function down(): void
    {
        if (!\Schema::hasTable('menu_items')) return;

        DB::table('menu_items')
            ->where('menu_key', 'menu-preferences')
            ->update(['href' => '/settings/menu-preferences']);
    }
};
