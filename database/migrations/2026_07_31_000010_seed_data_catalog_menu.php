<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan menu "Katalog & Silsilah Data" pada deployment yang sudah berjalan.
 *
 * Seeder hanya dijalankan saat pemasangan baru, sehingga tanpa migrasi ini
 * modulnya tidak akan pernah muncul di sidebar tenant yang sudah ada — modul
 * seolah tidak dikerjakan, padahal route-nya aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        if (DB::table('menu_items')->where('menu_key', 'data-catalog')->exists()) {
            return;
        }

        $menuId = (string) Str::uuid();
        $now = now();

        DB::table('menu_items')->insert([
            'id' => $menuId,
            'menu_key' => 'data-catalog',
            'label' => 'Katalog & Silsilah Data',
            'href' => '/data-catalog',
            'icon' => 'Network',
            'section' => 'Data Management',
            'sort_order' => 215,
            'hideable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('role_menu_whitelist')) {
            return;
        }

        // Peran yang sama dengan Data Discovery: katalog adalah lapisan di
        // atas hasil pemindaiannya, jadi siapa pun yang boleh melihat yang
        // satu seharusnya boleh melihat yang lain.
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

        $menu = DB::table('menu_items')->where('menu_key', 'data-catalog')->first();
        if (! $menu) {
            return;
        }
        if (Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->where('menu_id', $menu->id)->delete();
        }
        DB::table('menu_items')->where('id', $menu->id)->delete();
    }
};
