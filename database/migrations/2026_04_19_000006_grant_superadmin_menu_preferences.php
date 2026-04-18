<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * /menu-preferences is now editable by superadmin (sees all columns minus
 * root). Ensure the whitelist row exists so the menu shows up in their
 * sidebar without requiring a re-seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasTable('menu_items') || !\Schema::hasTable('role_menu_whitelist')) return;

        $menuId = DB::table('menu_items')->where('menu_key', 'menu-preferences')->value('id');
        if (!$menuId) return;

        $exists = DB::table('role_menu_whitelist')
            ->where('menu_id', $menuId)
            ->where('role', 'superadmin')
            ->exists();
        if ($exists) return;

        DB::table('role_menu_whitelist')->insert([
            'id' => (string) Str::uuid(),
            'menu_id' => $menuId,
            'role' => 'superadmin',
            'is_allowed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!\Schema::hasTable('menu_items') || !\Schema::hasTable('role_menu_whitelist')) return;
        $menuId = DB::table('menu_items')->where('menu_key', 'menu-preferences')->value('id');
        if (!$menuId) return;
        DB::table('role_menu_whitelist')
            ->where('menu_id', $menuId)
            ->where('role', 'superadmin')
            ->delete();
    }
};
