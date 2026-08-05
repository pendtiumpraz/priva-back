<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buang modul Pemberitahuan Privasi seluruhnya.
 *
 * Modul ini menduplikasi kemampuan yang sudah ada. Policy Generator sudah
 * menyusun naskah kebijakan privasi lewat wizard berbantuan AI — lengkap
 * dengan pemetaan tiap klausul ke Pasal UU PDP, skor cakupan, ekspor DOCX/PDF,
 * DAN `GET /policy/{id}/embed.html` untuk disematkan di situs klien.
 * Policy Review melengkapinya dari sisi lain: memeriksa dokumen yang sudah ada
 * terhadap UU PDP.
 *
 * Yang benar-benar tidak dimiliki keduanya hanya tiga hal — versi bertingkat,
 * alur persetujuan, dan penjadwalan terbit. Tiga hal itu tidak sepadan dengan
 * satu modul penuh berisi tiga tabel, dua controller, satu perintah terjadwal,
 * dan entri sidebar sendiri. Biayanya justru ditanggung pengguna: tiga menu
 * yang semuanya terdengar seperti "dokumen kebijakan privasi", dan mereka
 * harus menebak mana yang dipakai.
 *
 * Bila versi dan persetujuan kelak benar-benar dibutuhkan, tempatnya menempel
 * pada Policy Generator — di sana naskahnya sudah ada.
 *
 * Tabel-tabelnya sempat terbentuk di produksi karena migrasi pembuatnya
 * berjalan sebelum rangkaian berhenti di 000008, jadi menghapus berkas
 * migrasinya saja tidak cukup.
 */
return new class extends Migration
{
    private const MODULE = 'privacy_notice';

    public function up(): void
    {
        // Menu sidebar.
        if (Schema::hasTable('menu_items')) {
            $menu = DB::table('menu_items')->where('menu_key', 'privacy-notice')->first();
            if ($menu) {
                foreach (['role_menu_whitelist', 'tenant_menu_override'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'menu_id')) {
                        DB::table($table)->where('menu_id', $menu->id)->delete();
                    }
                }
                DB::table('menu_items')->where('id', $menu->id)->delete();
            }
        }

        // Izin yang sempat disemai ke peran tenant. Dibiarkan menggantung, ia
        // akan terus muncul di layar pengaturan peran sebagai modul yang tidak
        // ada lagi.
        if (Schema::hasTable('tenant_roles')) {
            TenantRole::query()->chunkById(200, function ($roles) {
                foreach ($roles as $role) {
                    $perms = is_array($role->permissions) ? $role->permissions : [];
                    if (! $perms) {
                        continue;
                    }

                    $kept = array_values(array_filter(
                        $perms,
                        fn ($p) => explode(':', (string) $p)[0] !== self::MODULE,
                    ));

                    if (count($kept) !== count($perms)) {
                        $role->permissions = $kept;
                        $role->saveQuietly();
                    }
                }
            });
        }

        // Urutan penting: konten menunjuk versi, versi menunjuk dokumen.
        Schema::dropIfExists('privacy_notice_contents');
        Schema::dropIfExists('privacy_notice_versions');
        Schema::dropIfExists('privacy_notices');
    }

    public function down(): void
    {
        // Sengaja tidak memulihkan apa pun. Controller, model, perintah
        // terjadwal, dan halamannya sudah tidak ada di repositori — tabel
        // kosong tanpa pembaca tidak menolong siapa pun.
    }
};
