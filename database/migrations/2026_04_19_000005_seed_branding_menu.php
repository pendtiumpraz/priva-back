<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasTable('menu_items')) return;

        $exists = DB::table('menu_items')->where('menu_key', 'branding')->exists();
        if ($exists) return;

        $now = now();
        $menuId = (string) Str::uuid();
        DB::table('menu_items')->insert([
            'id' => $menuId,
            'menu_key' => 'branding',
            'label' => 'Branding & Theme',
            'href' => '/branding',
            'icon' => 'Palette',
            'section' => 'Organisasi',
            'sort_order' => 960,
            'hideable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (\Schema::hasTable('role_menu_whitelist')) {
            foreach (['root', 'superadmin', 'admin'] as $role) {
                DB::table('role_menu_whitelist')->insert([
                    'id' => (string) Str::uuid(),
                    'menu_id' => $menuId,
                    'role' => $role,
                    'is_allowed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!\Schema::hasTable('menu_items')) return;
        $menuId = DB::table('menu_items')->where('menu_key', 'branding')->value('id');
        if ($menuId && \Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->where('menu_id', $menuId)->delete();
        }
        DB::table('menu_items')->where('menu_key', 'branding')->delete();
    }
};
