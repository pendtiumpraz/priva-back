<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daftarkan menu Pola Pengenal PII pada deployment berjalan.
 *
 * Semula migrasi ini juga mendaftarkan "Impor RoPA (CSV)" sebagai menu
 * tersendiri. Itu keliru dan sudah dicabut: impor adalah tindakan MILIK modul
 * RoPA — izinnya sama (`ropa,write`) dan hasilnya mendarat di tabel yang sama.
 * Orang yang hendak menambah RoPA secara massal membukanya dari halaman RoPA,
 * bukan mencari entri terpisah di sidebar yang sudah memuat puluhan modul.
 * Kini ia berupa tombol "Impor CSV" pada toolbar RoPA.
 *
 * Rutenya sendiri (`/api/ropa/import/*`) tidak berubah.
 */
return new class extends Migration
{
    private array $menus = [
        ['pii-patterns', 'Pola Pengenal PII', '/pii-patterns', 'Fingerprint', 'Data Management', 217, ['root', 'superadmin', 'admin', 'dpo', 'maker', 'viewer']],
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
