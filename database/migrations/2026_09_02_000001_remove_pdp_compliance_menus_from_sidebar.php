<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UX cleanup — hapus menu PPDP/DPO, Paparan Sanksi, dan Regulasi dari sidebar.
 *
 * Ketiga fitur dipindah ke halaman Pengaturan → grup "Kepatuhan PDP" yang hanya
 * dapat diakses admin tenant & DPO (lihat frontend settings + MenuRegistrySeeder
 * yang sudah tidak lagi mendaftarkan entri ini). Route /ppdp, /sanctions, dan
 * /regulations tetap aktif dan tetap dijaga permission backend.
 *
 * Membersihkan baris DB yang mungkin sudah ter-seed sebelumnya (MenuRegistrySeeder
 * pakai updateOrCreate sehingga entri lama tidak otomatis terhapus).
 */
return new class extends Migration
{
    private array $keys = ['ppdp', 'sanctions', 'regulations'];

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        foreach ($this->keys as $key) {
            $menuId = DB::table('menu_items')->where('menu_key', $key)->value('id');
            if (! $menuId) {
                continue;
            }

            if (Schema::hasTable('role_menu_whitelists')) {
                DB::table('role_menu_whitelists')->where('menu_id', $menuId)->delete();
            }
            if (Schema::hasTable('menu_preferences') && Schema::hasColumn('menu_preferences', 'menu_id')) {
                DB::table('menu_preferences')->where('menu_id', $menuId)->delete();
            }
            DB::table('menu_items')->where('id', $menuId)->delete();
        }
    }

    public function down(): void
    {
        // No-op: untuk memunculkan kembali, jalankan MenuRegistrySeeder dengan
        // entri ppdp/sanctions/regulations dimasukkan lagi (di-track manual).
    }
};
