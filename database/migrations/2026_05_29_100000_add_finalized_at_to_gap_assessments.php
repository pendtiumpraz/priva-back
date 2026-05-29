<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pisahkan konsep "selesai (locked)" dari "progress 100%".
 *
 * Sebelum perubahan ini, gap_assessments hanya punya kolom `progress`.
 * UI menyembunyikan tombol Edit ketika progress = 100. Tapi `progress`
 * selalu di-recompute dari answered_count/total_count di submitAnswers,
 * jadi tombol Save (yang juga submit semua jawaban) otomatis bikin
 * progress = 100 jika user sudah jawab semua — bahkan kalau user
 * masih draft mode (duplicate, atau memang belum final). Akibatnya:
 *   - Duplicate dengan answers lengkap → langsung lock (tidak bisa edit).
 *   - User klik Save & Exit di tengah revisi → langsung lock.
 *
 * Tambah `finalized_at` (nullable timestamp). Hanya tombol "Selesaikan"
 * (Finish) yang men-set timestamp ini. Save & Exit tidak nyentuh.
 * Frontend pakai field ini (bukan progress) untuk menentukan apakah
 * assessment masih editable.
 *
 * Backfill: assessment lama dengan progress >= 100 dianggap sudah
 * finalized (set finalized_at = created_at) supaya tidak tiba-tiba
 * unlock setelah migrate. Yang progress < 100 di-leave NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('gap_assessments', 'finalized_at')) {
            Schema::table('gap_assessments', function (Blueprint $table) {
                $table->timestamp('finalized_at')->nullable()->after('progress')->index();
            });
        }

        // Backfill: tandai semua assessment dengan progress >= 100 sebagai sudah finalized.
        DB::table('gap_assessments')
            ->where('progress', '>=', 100)
            ->whereNull('finalized_at')
            ->update(['finalized_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('gap_assessments', 'finalized_at')) {
            Schema::table('gap_assessments', function (Blueprint $table) {
                $table->dropColumn('finalized_at');
            });
        }
    }
};
