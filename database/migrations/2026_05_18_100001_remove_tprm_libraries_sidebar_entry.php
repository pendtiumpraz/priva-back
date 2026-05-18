<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UX cleanup — hapus menu "Bank Pertanyaan TPRM" dari sidebar.
 *
 * Bank Pertanyaan adalah sub-feature dari TPRM (configure template
 * questionnaire untuk vendor). Lebih natural sebagai tombol di dalam
 * halaman /vendor-risk daripada menu terpisah yang bikin parent TPRM
 * + child sama-sama highlight saat di /vendor-risk/libraries.
 *
 * Route /vendor-risk/libraries tetap aktif. Akses lewat tombol "Bank
 * Pertanyaan" di header halaman /vendor-risk.
 */
return new class extends Migration {
    public function up(): void
    {
        // Defensive: table `menu_items` mungkin tidak ada di env tertentu
        // (test/sandbox). Skip kalau begitu.
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        $menuId = DB::table('menu_items')->where('menu_key', 'tprm-libraries')->value('id');
        if ($menuId) {
            // `role_menu_whitelists` belum ada migration di codebase — di-create
            // manual di env dev. Skip cleanup whitelist kalau table tidak ada.
            if (Schema::hasTable('role_menu_whitelists')) {
                DB::table('role_menu_whitelists')->where('menu_id', $menuId)->delete();
            }
            DB::table('menu_items')->where('id', $menuId)->delete();
        }
    }

    public function down(): void
    {
        // No-op: kalau perlu munculkan kembali, jalankan MenuRegistrySeeder
        // dengan entry tprm-libraries dimasukkan kembali (di-track manual).
    }
};
