<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan menu "Peta Alur Data" pada deployment yang sudah berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        if (DB::table('menu_items')->where('menu_key', 'ropa-data-flow')->exists()) {
            return;
        }

        $menuId = (string) Str::uuid();
        $now = now();

        DB::table('menu_items')->insert([
            'id' => $menuId,
            'menu_key' => 'ropa-data-flow',
            'label' => 'Peta Alur Data',
            'href' => '/ropa-data-flow',
            'icon' => 'Workflow',
            'section' => 'PDP Modules',
            'sort_order' => 175,
            'hideable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('role_menu_whitelist')) {
            return;
        }

        foreach (['root', 'superadmin', 'admin', 'dpo', 'maker', 'viewer'] as $role) {
            DB::table('role_menu_whitelist')->insert([
                'id' => (string) Str::uuid(),
                'menu_id' => $menuId,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        $menu = DB::table('menu_items')->where('menu_key', 'ropa-data-flow')->first();
        if (! $menu) {
            return;
        }
        if (Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->where('menu_id', $menu->id)->delete();
        }
        DB::table('menu_items')->where('id', $menu->id)->delete();
    }
};
