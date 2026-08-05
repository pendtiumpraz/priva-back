<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cabut menu sidebar "Impor RoPA (CSV)".
 *
 * Impor CSV kini berupa tombol pada toolbar halaman RoPA, bukan halaman
 * tersendiri. Alasannya: impor adalah tindakan MILIK modul RoPA — izinnya sama
 * (`ropa,write`) dan hasilnya mendarat di tabel yang sama. Menaruhnya sebagai
 * entri sidabar terpisah memisahkan tindakan dari objeknya, dan menambah satu
 * baris lagi ke sidebar yang sudah memuat puluhan modul.
 *
 * Migrasi 000014 sudah tidak lagi mendaftarkannya, jadi deployment yang belum
 * pernah menjalankannya tidak akan pernah punya menu ini. Migrasi ini menyasar
 * lingkungan yang sempat menjalankan 000014 versi lama — terutama mesin
 * pengembangan — dan tidak melakukan apa-apa bila menunya memang tidak ada.
 *
 * Rute API `/api/ropa/import/*` tidak disentuh: yang dicabut hanya pintu
 * masuknya di sidebar, bukan kemampuannya.
 */
return new class extends Migration
{
    private const MENU_KEY = 'ropa-import';

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        $menu = DB::table('menu_items')->where('menu_key', self::MENU_KEY)->first();
        if (! $menu) {
            return;
        }

        // Preferensi per-tenant dan daftar putih peran menunjuk ke menu ini.
        // Membiarkannya menggantung akan meninggalkan baris yatim yang menunjuk
        // ke menu yang sudah tidak ada.
        foreach (['role_menu_whitelist', 'tenant_menu_override'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'menu_id')) {
                DB::table($table)->where('menu_id', $menu->id)->delete();
            }
        }

        DB::table('menu_items')->where('id', $menu->id)->delete();
    }

    public function down(): void
    {
        // Sengaja tidak memulihkan menunya. Halaman yang ditunjuknya
        // (`/ropa-import`) sudah dihapus dari frontend, jadi mengembalikan
        // entri sidebar hanya akan menghasilkan tautan yang menuju halaman
        // tidak ditemukan.
    }
};
