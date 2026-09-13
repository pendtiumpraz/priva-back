<?php

namespace App\Console\Commands;

use App\Models\ConsentRuleDecision;
use Illuminate\Console\Command;

/**
 * Membuang jejak keputusan consent yang lebih tua dari ambang retensi.
 *
 * Jejak ini menyimpan penanda subjek — biasanya alamat surel — satu baris per
 * penangkapan. Tanpa pemangkasan, sebuah tabel yang dibuat demi kepatuhan justru
 * tumbuh menjadi timbunan data pribadi tanpa batas waktu. Bawaannya 365 hari:
 * cukup panjang untuk menjawab audit setahun ke belakang, dan berhenti di situ.
 *
 * Jadwal: routes/console.php — harian pukul 02:45, berdampingan dengan
 * pemangkasan cookie_logs.
 */
class PruneConsentRuleDecisionsCommand extends Command
{
    protected $signature = 'consent:prune-rule-decisions
                            {--days=365 : Hari retensi (baris lebih tua dari ini dihapus permanen)}
                            {--dry : Hitung saja, tanpa menghapus}';

    protected $description = 'Hapus permanen jejak keputusan aturan consent yang melewati masa retensi';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // withoutGlobalScope: perintah artisan berjalan tanpa CurrentOrgContext,
        // dan pemangkasan ini memang lintas tenant menurut rancangannya.
        $query = ConsentRuleDecision::withoutGlobalScope('org')->where('decided_at', '<', $cutoff);

        $count = $query->count();

        if ($this->option('dry')) {
            $this->info("[dry] Akan memangkas {$count} jejak keputusan sebelum {$cutoff->toDateTimeString()}");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Tidak ada yang perlu dipangkas.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Memangkas {$deleted} jejak keputusan lebih tua dari {$days} hari.");

        return self::SUCCESS;
    }
}
