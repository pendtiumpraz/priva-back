<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nama tampilan resmi dua modul subjek (keputusan produk):
 *
 *   consent-guardian       Children Pro        — consent anak (PP 33/2026 Ps 38)
 *   consent-accessibility  Inclusive Privacy   — consent disabilitas (Ps 39)
 *
 * Nama kerja lama ("Consent Wali (Anak)" / "Consent Aksesibilitas
 * (Disabilitas)") sudah terlanjur tersimpan di `menu_items` pada instalasi
 * yang menjalankan migrasi 000005; migrasi 000005 sendiri diperbarui untuk
 * instalasi baru. Hanya LABEL yang berubah: menu_key, href, izin, dan
 * owner_module tetap — tidak ada tautan atau kode yang bergeser.
 */
return new class extends Migration
{
    private const NAMA = [
        'consent-guardian' => ['baru' => 'Children Pro', 'lama' => 'Consent Wali (Anak)'],
        'consent-accessibility' => ['baru' => 'Inclusive Privacy', 'lama' => 'Consent Aksesibilitas (Disabilitas)'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        foreach (self::NAMA as $menuKey => $nama) {
            DB::table('menu_items')->where('menu_key', $menuKey)->update(['label' => $nama['baru'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        foreach (self::NAMA as $menuKey => $nama) {
            DB::table('menu_items')->where('menu_key', $menuKey)->update(['label' => $nama['lama'], 'updated_at' => now()]);
        }
    }
};
