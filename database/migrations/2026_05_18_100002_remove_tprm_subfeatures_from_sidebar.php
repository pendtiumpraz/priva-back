<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UX cleanup — hapus 4 sub-feature TPRM dari sidebar:
 *   - tprm-inbox-review
 *   - tprm-inbox-approval
 *   - tprm-monitoring
 *   - tprm-incidents
 *
 * Sekarang navigate antar TPRM sub-feature lewat <TprmSubNav /> pill bar
 * yang muncul di top setiap halaman TPRM. Sidebar cuma punya 1 entry
 * "Third Party Management" (/vendor-risk).
 *
 * Route tetap aktif, akses dari TprmSubNav atau direct URL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        $keys = ['tprm-inbox-review', 'tprm-inbox-approval', 'tprm-monitoring', 'tprm-incidents'];
        $menuIds = DB::table('menu_items')->whereIn('menu_key', $keys)->pluck('id');
        if ($menuIds->isNotEmpty()) {
            if (Schema::hasTable('role_menu_whitelists')) {
                DB::table('role_menu_whitelists')->whereIn('menu_id', $menuIds)->delete();
            }
            DB::table('menu_items')->whereIn('id', $menuIds)->delete();
        }
    }

    public function down(): void
    {
        // No-op: balikkan kalau perlu via MenuRegistrySeeder dengan entry
        // ditambahkan kembali manual.
    }
};
