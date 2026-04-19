<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasTable('menu_items')) return;

        if (DB::table('menu_items')->where('menu_key', 'platform-config')->exists()) return;

        $menuId = (string) Str::uuid();
        $now = now();

        DB::table('menu_items')->insert([
            'id' => $menuId,
            'menu_key' => 'platform-config',
            'label' => 'Platform Config',
            'href' => '/platform-config',
            'icon' => 'Settings',
            'section' => 'Platform (Root)',
            'sort_order' => 760,
            'hideable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (\Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->insert([
                'id' => (string) Str::uuid(),
                'menu_id' => $menuId,
                'role' => 'root',
                'is_allowed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!\Schema::hasTable('menu_items')) return;
        $id = DB::table('menu_items')->where('menu_key', 'platform-config')->value('id');
        if ($id && \Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->where('menu_id', $id)->delete();
        }
        DB::table('menu_items')->where('menu_key', 'platform-config')->delete();
    }
};
