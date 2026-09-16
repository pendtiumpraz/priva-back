<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 10 — dua modul sidebar per SUBJEK, tepat setelah Consent:
 *
 *   consent-guardian       Consent Wali (anak)             /consent-guardian
 *   consent-accessibility  Consent Aksesibilitas (disabilitas) /consent-accessibility
 *
 * Masing-masing LENGKAP: titik pengumpulan sendiri (CRUD), kewenangan wali,
 * dan DSR atas nama subjek kelas itu. Sampai fase ini semuanya diparkir di
 * bawah /consent/* dengan izin `consent` — dua fitur tanpa rumah, tanpa izin
 * dan entitlement sendiri, dan menu Consent ikut menyala di halaman yang
 * bukan miliknya.
 *
 * Whitelist peran mengikuti `consent` (root, admin, dpo, maker) — lihat
 * MenuRegistrySeeder. Untuk peran berbasis izin, plafonnya whitelist admin,
 * dan gerbangnya izin modul (backfill di migrasi 000006). Entitlement tidak
 * perlu backfill: tanpa baris = terbuka; root mencabut lewat Menu Control.
 */
return new class extends Migration
{
    private const MENU = [
        [
            'menu_key' => 'consent-guardian',
            'label' => 'Consent Wali (Anak)',
            'href' => '/consent-guardian',
            'icon' => 'Baby',
            'sort_order' => 321,
        ],
        [
            'menu_key' => 'consent-accessibility',
            'label' => 'Consent Aksesibilitas (Disabilitas)',
            'href' => '/consent-accessibility',
            'icon' => 'Accessibility',
            'sort_order' => 322,
        ],
    ];

    private const PERAN = ['root', 'admin', 'dpo', 'maker'];

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        $now = now();

        foreach (self::MENU as $menu) {
            if (DB::table('menu_items')->where('menu_key', $menu['menu_key'])->exists()) {
                continue;
            }

            $menuId = (string) Str::uuid();

            DB::table('menu_items')->insert($menu + [
                'id' => $menuId,
                'section' => 'Subject Rights',
                'hideable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! Schema::hasTable('role_menu_whitelist')) {
                continue;
            }

            foreach (self::PERAN as $role) {
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
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        foreach (self::MENU as $menu) {
            $baris = DB::table('menu_items')->where('menu_key', $menu['menu_key'])->first();
            if (! $baris) {
                continue;
            }
            // Tabel whitelist-nya TUNGGAL (`role_menu_whitelist`), bukan jamak —
            // migrasi 2026_09_02_000001 salah eja dan penghapusannya tak pernah jalan.
            if (Schema::hasTable('role_menu_whitelist')) {
                DB::table('role_menu_whitelist')->where('menu_id', $baris->id)->delete();
            }
            DB::table('menu_items')->where('id', $baris->id)->delete();
        }
    }
};
