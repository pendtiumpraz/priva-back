<?php

namespace App\Console\Commands;

use App\Models\VendorAssessment;
use App\Services\CanonicalPdpLibraryService;
use Illuminate\Console\Command;

/**
 * One-time (idempotent) backfill — Rework TPRM 2026-07.
 *
 * Set `vendor_assessments.library_id` = library UU PDP kanonik untuk row yang
 * `library_id IS NULL`. Historis, library_id null = jenis "Default" yang selalu
 * merender pertanyaan UU PDP (effectiveForOrg → pdp_compliance v2_2026). Dengan
 * menghapus konsep null-Default dan menyatukannya ke library UU PDP kanonik,
 * hitungan asesmen per vendor tidak lagi dobel (null-Default + library UU PDP
 * yang sebelumnya terhitung 2×).
 *
 * Fork-aware per org: bila org punya fork COW dari template UU PDP, row null
 * milik org tsb diarahkan ke fork itu (konsisten dengan picker Bank Pertanyaan).
 *
 * Idempotent — setelah dijalankan tidak ada lagi row library_id null; run ulang
 * no-op. Jalankan di dev + prod:
 *   php artisan tprm:backfill-pdp-library-id [--dry-run]
 */
class BackfillPdpLibraryId extends Command
{
    protected $signature = 'tprm:backfill-pdp-library-id {--dry-run : Tampilkan rencana tanpa menulis}';

    protected $description = 'Backfill vendor_assessments.library_id null → library UU PDP kanonik (unifikasi Default)';

    public function handle(CanonicalPdpLibraryService $canonical): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — tidak menulis' : 'Backfill live');

        // Group per org: canonical fork-aware di-resolve satu kali per org.
        $orgIds = VendorAssessment::query()
            ->whereNull('library_id')
            ->distinct()
            ->pluck('org_id');

        if ($orgIds->isEmpty()) {
            $this->info('Tidak ada row library_id null — tidak ada yang di-backfill.');

            return self::SUCCESS;
        }

        $totalUpdated = 0;
        foreach ($orgIds as $orgId) {
            $libraryId = $canonical->resolveId($orgId);

            $count = VendorAssessment::query()
                ->whereNull('library_id')
                ->where('org_id', $orgId)
                ->count();

            if (! $dry) {
                VendorAssessment::query()
                    ->whereNull('library_id')
                    ->where('org_id', $orgId)
                    ->update(['library_id' => $libraryId]);
            }

            $totalUpdated += $count;
            $this->line("  org {$orgId}: {$count} row → library {$libraryId}");
        }

        $this->info(($dry ? '[dry] ' : '')."Selesai. {$totalUpdated} asesmen di-backfill ke library UU PDP kanonik.");

        return self::SUCCESS;
    }
}
