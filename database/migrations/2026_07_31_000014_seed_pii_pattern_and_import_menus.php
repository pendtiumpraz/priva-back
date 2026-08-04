<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan menu Pola Pengenal PII dan Impor RoPA pada deployment berjalan.
 *
 * Impor RoPA sengaja dibatasi peran yang dapat menulis: mengimpor ratusan RoPA
 * sekaligus mengubah isi modul secara masif, dan itu bukan tindakan yang layak
 * dibuka untuk peran baca-saja.
 */
return new class extends Migration
{
    private array $menus = [
        ['pii-patterns', 'Pola Pengenal PII', '/pii-patterns', 'Fingerprint', 'Data Management', 217, ['root', 'superadmin', 'admin', 'dpo', 'maker', 'viewer']],
        ['ropa-import', 'Impor RoPA (CSV)', '/ropa-import', 'FileSpreadsheet', 'PDP Modules', 176, ['root', 'superadmin', 'admin', 'dpo', 'maker']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        $now = now();

        foreach ($this->menus as [$key, $label, $href, $icon, $section, $sort, $roles]) {
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

            foreach ($roles as $role) {
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
