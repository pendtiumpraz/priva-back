<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan menu "Dashboard Kustom" pada deployment yang sudah berjalan.
 *
 * Ditempatkan di bagian Organisasi, bukan di salah satu modul: dashboard
 * kustom menggabungkan angka LINTAS modul, sehingga menaruhnya di bawah satu
 * modul tertentu akan menyesatkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        if (DB::table('menu_items')->where('menu_key', 'custom-dashboards')->exists()) {
            return;
        }

        $menuId = (string) Str::uuid();
        $now = now();

        DB::table('menu_items')->insert([
            'id' => $menuId,
            'menu_key' => 'custom-dashboards',
            'label' => 'Dashboard Kustom',
            'href' => '/custom-dashboards',
            'icon' => 'LayoutDashboard',
            'section' => 'Organisasi',
            'sort_order' => 940,
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

        $menu = DB::table('menu_items')->where('menu_key', 'custom-dashboards')->first();
        if (! $menu) {
            return;
        }
        if (Schema::hasTable('role_menu_whitelist')) {
            DB::table('role_menu_whitelist')->where('menu_id', $menu->id)->delete();
        }
        DB::table('menu_items')->where('id', $menu->id)->delete();
    }
};
