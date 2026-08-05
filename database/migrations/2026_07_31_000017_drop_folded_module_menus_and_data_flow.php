<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cabut menu sidebar modul-modul yang dilebur, dan buang tabel peta alur RoPA.
 *
 * Enam kemampuan didaftarkan sebagai entri sidebar tersendiri padahal semuanya
 * milik modul yang sudah ada — izinnya sama, datanya sama, dan satu-satunya
 * alasan seseorang membukanya adalah karena sedang mengurus modul induknya.
 * Memisahkannya menjauhkan tindakan dari objeknya sekaligus memanjangkan
 * sidebar yang sudah memuat puluhan entri:
 *
 *   ropa-data-flow    -> dihapus seluruhnya (lihat di bawah)
 *   control-library   -> pemilih di dalam DPIA / Risk Treatment Plan
 *   data-catalog      -> tab di dalam Data Discovery
 *   discovery-probe   -> tab di dalam Data Discovery
 *   pii-patterns      -> tab di dalam Data Discovery
 *   dsr-channels      -> tab di dalam DSR
 *
 * Migrasi penyemainya sudah dihapus dari repositori, sehingga deployment yang
 * belum menjalankannya tidak akan pernah memiliki menu-menu ini. Migrasi ini
 * menyasar lingkungan yang sempat menjalankan versi lama — terutama mesin
 * pengembangan — dan tidak melakukan apa-apa bila menunya memang tidak ada.
 *
 * TABEL ropa_data_flows ikut dibuang. Modul Peta Alur Data ternyata
 * menduplikasi ProcessingDiagram yang sudah lama ada di dalam penyuntingan
 * RoPA — sasarannya sama (satu RoPA), sementara yang sudah ada bahkan punya
 * unduhan PNG dan SVG yang tidak dimiliki modul baru. Yang diduplikasi
 * dipertahankan, yang menduplikasi dibuang.
 *
 * Tabelnya sempat terbentuk di produksi karena migrasi pembuatnya berjalan
 * sebelum rangkaian ini berhenti di 000008, jadi ia perlu dibuang secara
 * eksplisit — bukan sekadar dihapus berkas migrasinya.
 */
return new class extends Migration
{
    private const MENU_KEYS = [
        'ropa-data-flow',
        'control-library',
        'data-catalog',
        'discovery-probe',
        'pii-patterns',
        'dsr-channels',
    ];

    public function up(): void
    {
        if (Schema::hasTable('menu_items')) {
            foreach (self::MENU_KEYS as $key) {
                $menu = DB::table('menu_items')->where('menu_key', $key)->first();
                if (! $menu) {
                    continue;
                }

                // Daftar putih peran dan penimpaan per-tenant menunjuk ke menu
                // ini. Membiarkannya menggantung meninggalkan baris yatim yang
                // menunjuk ke menu yang sudah tidak ada.
                foreach (['role_menu_whitelist', 'tenant_menu_override'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'menu_id')) {
                        DB::table($table)->where('menu_id', $menu->id)->delete();
                    }
                }

                DB::table('menu_items')->where('id', $menu->id)->delete();
            }
        }

        Schema::dropIfExists('ropa_data_flows');
    }

    public function down(): void
    {
        // Sengaja tidak memulihkan apa pun. Halaman yang ditunjuk menu-menu itu
        // sudah tidak ada di frontend, dan tabel peta alur tidak punya
        // pembacanya lagi — mengembalikan keduanya hanya menghasilkan tautan
        // buntu dan tabel mati.
    }
};
