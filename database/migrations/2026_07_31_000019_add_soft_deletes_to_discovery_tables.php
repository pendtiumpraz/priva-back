<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lengkapi soft delete pada tabel Data Discovery yang terlewat.
 *
 * `CLAUDE.md` menyebut soft delete dan penyaringan org_id sebagai dua jaminan
 * universal skema ini. Tiga tabel berikut dibuat tanpa `deleted_at`, sehingga
 * penghapusannya permanen:
 *
 *   data_catalog_lineage    — tepi silsilah. Yang paling merugikan: tepi yang
 *                             ditambahkan MANUAL adalah pengetahuan yang
 *                             diketik orang dan tidak dapat dibangun ulang
 *                             lewat sinkronisasi. Sekali salah klik, hilang.
 *   discovery_probe_configs — konfigurasi penemuan, memuat kredensial dan
 *                             rentang jaringan yang disusun berjam-jam.
 *   discovery_candidates    — kandidat basis data hasil temuan; menghapusnya
 *                             permanen berarti kehilangan jejak bahwa sesuatu
 *                             pernah ditemukan di sana.
 *
 * Kolomnya ditambahkan, bukan tabelnya dibuat ulang, supaya baris yang sudah
 * ada di produksi tetap utuh.
 */
return new class extends Migration
{
    private const TABLES = [
        'data_catalog_lineage',
        'discovery_probe_configs',
        'discovery_candidates',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
