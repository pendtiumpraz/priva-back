<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        $menuId = DB::table('menu_items')->where('menu_key', 'tprm-libraries')->value('id');
        if ($menuId) {
            DB::table('role_menu_whitelists')->where('menu_id', $menuId)->delete();
            DB::table('menu_items')->where('id', $menuId)->delete();
        }
    }

    public function down(): void
    {
        // No-op: kalau perlu munculkan kembali, jalankan MenuRegistrySeeder
        // dengan entry tprm-libraries dimasukkan kembali (di-track manual).
    }
};
