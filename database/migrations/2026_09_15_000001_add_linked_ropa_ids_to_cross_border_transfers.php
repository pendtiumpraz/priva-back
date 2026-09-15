<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan banyak-RoPA untuk transfer lintas negara.
 *
 * Satu transfer bisa melayani beberapa kegiatan pemrosesan sekaligus — kiriman
 * berkala ke satu penyedia email dipakai pemasaran DAN notifikasi transaksi,
 * misalnya. Kolom tunggal memaksa memilih salah satu, dan yang tidak dipilih
 * hilang dari jejaknya.
 *
 * Bentuknya sengaja dicermin dari `breach_incidents`: `linked_ropa_id` yang lama
 * DIPERTAHANKAN berisi tautan pertama, supaya pembaca lama (validasi
 * CrossBorderController, tampilan rincian, ekspor) tetap bekerja tanpa diubah
 * serentak. Daftar penuhnya di kolom baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cross_border_transfers', 'linked_ropa_ids')) {
            return;
        }
        try {
            Schema::table('cross_border_transfers', function (Blueprint $table) {
                $table->json('linked_ropa_ids')->nullable()->after('linked_ropa_id');
            });
        } catch (QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) {
                return;
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cross_border_transfers', 'linked_ropa_ids')) {
            return;
        }
        try {
            Schema::table('cross_border_transfers', function (Blueprint $table) {
                $table->dropColumn('linked_ropa_ids');
            });
        } catch (QueryException $e) { /* sudah tidak ada */
        }
    }
};
