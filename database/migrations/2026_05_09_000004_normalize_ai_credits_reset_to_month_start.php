<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize ai_credits_reset_at ke awal bulan kalender (tanggal 1 jam 00:00)
 * dan recompute ai_credits_remaining berdasarkan log usage sejak awal bulan
 * kalender saat ini.
 *
 * Sebelum patch ini, resetIfNeeded() pakai now()->addMonth() yang rolling
 * 30 hari dari saat trigger reset, sedangkan dashboard pakai startOfMonth()
 * untuk hitung "used this month" — akibatnya muncul kasus aneh seperti
 * limit=500, used=91, remaining=474 (selisih 65 dari log sebelum reset
 * tengah-bulan terhitung di used_this_month tapi udah dipotong dari
 * remaining cycle sebelumnya).
 *
 * Migration ini menyelaraskan keduanya supaya konsisten:
 *   remaining = max(0, monthly_limit - sum(log credits this calendar month))
 *   reset_at  = startOfMonth() + 1 month
 */
return new class extends Migration
{
    public function up(): void
    {
        $startOfMonth = now()->startOfMonth();
        $nextReset = now()->addMonth()->startOfMonth();

        $orgs = DB::table('organizations')
            ->whereNotNull('ai_credits_monthly')
            ->where('ai_credits_monthly', '>', 0)
            ->get(['id', 'ai_credits_monthly']);

        foreach ($orgs as $o) {
            $used = (float) DB::table('ai_credit_logs')
                ->where('org_id', $o->id)
                ->where('status', 'success')
                ->where('created_at', '>=', $startOfMonth)
                ->sum('credits_used');

            $newRemaining = max(0.0, (float) $o->ai_credits_monthly - $used);

            DB::table('organizations')->where('id', $o->id)->update([
                'ai_credits_remaining' => $newRemaining,
                'ai_credits_reset_at' => $nextReset,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No rollback — angka remaining sudah accurate; kembali ke state lama
        // berarti re-introduce inkonsistensi.
    }
};
