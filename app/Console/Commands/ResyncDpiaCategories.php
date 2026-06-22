<?php

namespace App\Console\Commands;

use App\Models\DpiaCategory;
use App\Models\DpiaCategoryRisk;
use App\Models\Organization;
use App\Services\DpiaCategoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-seed kategori DPIA (21 kategori baku + teks pertanyaan) untuk org.
 *
 * ensureSeeded() hanya men-seed org yang BELUM punya kategori, jadi org yang
 * sudah terlanjur ter-seed dengan set kategori lama (prinsip GDPR generik) perlu
 * di-refresh ke set baru (Legal Basis, Retensi, Autentikasi, … + teks pertanyaan).
 *
 * PERHATIAN: command ini MENGHAPUS kategori + risk-event default per org lalu
 * men-seed ulang. Kustomisasi kategori/risk yang dibuat DPO akan hilang. Jawaban
 * DPIA lama (dpias.wizard_data) TIDAK disentuh (sesuai keputusan "biarkan apa adanya").
 *
 *   php artisan dpia:resync-categories            # semua org
 *   php artisan dpia:resync-categories {orgId}    # satu org
 */
class ResyncDpiaCategories extends Command
{
    protected $signature = 'dpia:resync-categories {org? : org_id tertentu (opsional)} {--force : Lewati konfirmasi}';

    protected $description = 'Re-seed 21 kategori DPIA + teks pertanyaan (menghapus kategori lama per org)';

    public function handle(): int
    {
        $orgArg = $this->argument('org');
        $orgIds = $orgArg
            ? [$orgArg]
            : DpiaCategory::query()->distinct()->pluck('org_id')->all();

        // Sertakan juga org yang belum punya kategori sama sekali (kalau target semua).
        if (! $orgArg) {
            $orgIds = array_values(array_unique(array_merge($orgIds, Organization::pluck('id')->all())));
        }

        if (empty($orgIds)) {
            $this->warn('Tidak ada org untuk diproses.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            count($orgIds).' org akan di-resync kategori DPIA-nya (kategori & risk default lama dihapus). Lanjut?'
        )) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($orgIds as $orgId) {
            DB::transaction(function () use ($orgId) {
                $catIds = DpiaCategory::where('org_id', $orgId)->pluck('id');
                if ($catIds->isNotEmpty()) {
                    DpiaCategoryRisk::whereIn('category_id', $catIds)->delete();
                    DpiaCategory::where('org_id', $orgId)->delete();
                }
                DpiaCategoryService::seedFor($orgId);
            });
            $done++;
        }

        $this->info("✅ {$done} org di-resync ke 21 kategori DPIA baru + teks pertanyaan.");

        return self::SUCCESS;
    }
}
