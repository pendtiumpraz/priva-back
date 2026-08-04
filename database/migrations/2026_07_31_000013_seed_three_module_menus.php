<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan tiga menu modul baru pada deployment yang sudah berjalan.
 *
 * Digabung dalam satu migrasi karena ketiganya lahir bersamaan dan tidak ada
 * alasan menjalankannya terpisah — memecahnya hanya menambah tiga berkas yang
 * isinya identik kecuali nilainya.
 */
return new class extends Migration
{
    private array $menus = [
        ['control-library', 'Pustaka Kontrol', '/control-library', 'ShieldCheck', 'PDP Modules', 178],
        ['discovery-probe', 'Penemuan Data Store', '/discovery-probe', 'Radar', 'Data Management', 218],
        ['dsr-channels', 'Kanal DSR', '/dsr-channels', 'Share2', 'Data Management', 219],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        $now = now();

        foreach ($this->menus as [$key, $label, $href, $icon, $section, $sort]) {
            if (DB::table('menu_items')->where('menu_key', $key)->exists()) {
                continue;
            }

            $menuId = (string) Str::uuid();
            DB::table('menu_items')->insert([
                'id' => $menuId,
                'menu_key' => $key,
                'label' => $label,
                'href' => $href,
                'icon' => $icon,
                'section' => $section,
                'sort_order' => $sort,
                'hideable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! Schema::hasTable('role_menu_whitelist')) {
                continue;
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        foreach ($this->menus as [$key]) {
            $menu = DB::table('menu_items')->where('menu_key', $key)->first();
            if (! $menu) {
                continue;
            }
            if (Schema::hasTable('role_menu_whitelist')) {
                DB::table('role_menu_whitelist')->where('menu_id', $menu->id)->delete();
            }
            DB::table('menu_items')->where('id', $menu->id)->delete();
        }
    }
};
