<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti penyajian yang dapat diakses — PP 33/2026 Pasal 39 ayat (3).
 *
 * `consent_logs.accessibility_meta` (JSON, nullable): format yang dipakai saat
 * menyetujui, apakah didampingi, hubungan pendamping. Diisi HANYA untuk subjek
 * disabilitas oleh App\Support\BuktiAksesibilitas; baris lain tetap NULL.
 *
 * Tanpa indeks dan tanpa foreign key — ledger tambah-saja yang bisa jutaan
 * baris; kolom ini dibaca per baris (detail/ekspor), tidak pernah disaring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consent_logs') || Schema::hasColumn('consent_logs', 'accessibility_meta')) {
            return;
        }

        Schema::table('consent_logs', function (Blueprint $t) {
            $t->json('accessibility_meta')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('consent_logs') && Schema::hasColumn('consent_logs', 'accessibility_meta')) {
            Schema::table('consent_logs', function (Blueprint $t) {
                $t->dropColumn('accessibility_meta');
            });
        }
    }
};
