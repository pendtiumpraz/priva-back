<?php

namespace App\Console\Commands;

use App\Models\Dpia;
use App\Models\InformationSystem;
use App\Models\Ropa;
use Illuminate\Console\Command;

/**
 * One-time backfill: walk existing ROPAs/DPIAs and materialize the pivot tables
 * from their wizard_data fields. Subsequent saves use the auto-sync hook in
 * ModuleCrudController.
 *
 * Run: php artisan ropa:backfill-pivots [--dry-run]
 */
class BackfillRopaPivots extends Command
{
    protected $signature = 'ropa:backfill-pivots {--dry-run : Print intended writes without persisting}';
    protected $description = 'Backfill information_system_ropa + dpia_ropa pivots from existing wizard_data';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — no writes' : 'Live backfill');

        $ropaPivotInserts = 0;
        $dpiaPivotInserts = 0;
        $ropaSkipped = 0;
        $dpiaSkipped = 0;

        Ropa::query()->orderBy('id')->chunkById(100, function ($chunk) use (&$ropaPivotInserts, &$ropaSkipped, $dry) {
            foreach ($chunk as $ropa) {
                $wizard = $ropa->wizard_data ?? [];
                $section = $wizard['detail_pemrosesan'] ?? [];
                $raw = $section['sistem_terkait'] ?? null;
                if (!is_array($raw) || empty($raw)) { $ropaSkipped++; continue; }

                $ids = collect($raw)
                    ->map(fn($v) => is_array($v) ? ($v['id'] ?? null) : (is_string($v) ? $v : null))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $valid = InformationSystem::whereIn('id', $ids)->where('org_id', $ropa->org_id)->pluck('id')->all();
                if (empty($valid)) { $ropaSkipped++; continue; }

                if (!$dry) {
                    $sync = [];
                    foreach ($valid as $id) $sync[$id] = ['org_id' => $ropa->org_id];
                    $ropa->informationSystems()->syncWithoutDetaching($sync);
                }
                $ropaPivotInserts += count($valid);
                $this->line("  ROPA {$ropa->registration_number}: " . count($valid) . " IS link" . ($dry ? ' [skipped]' : ''));
            }
        });

        Dpia::query()->orderBy('id')->chunkById(100, function ($chunk) use (&$dpiaPivotInserts, &$dpiaSkipped, $dry) {
            foreach ($chunk as $dpia) {
                $wizard = $dpia->wizard_data ?? [];
                $section = $wizard['koneksi_ropa'] ?? [];
                $ids = array_filter(array_unique($section['connected_ropas'] ?? []));
                // include legacy single ropa_id
                if ($dpia->ropa_id && !in_array($dpia->ropa_id, $ids, true)) $ids[] = $dpia->ropa_id;

                if (empty($ids)) { $dpiaSkipped++; continue; }

                $valid = Ropa::whereIn('id', $ids)->where('org_id', $dpia->org_id)->pluck('id')->all();
                if (empty($valid)) { $dpiaSkipped++; continue; }

                if (!$dry) {
                    $sync = [];
                    foreach ($valid as $id) $sync[$id] = ['org_id' => $dpia->org_id];
                    $dpia->ropas()->syncWithoutDetaching($sync);
                }
                $dpiaPivotInserts += count($valid);
                $this->line("  DPIA {$dpia->registration_number}: " . count($valid) . " ROPA link" . ($dry ? ' [skipped]' : ''));
            }
        });

        $this->newLine();
        $this->info("ROPA→IS: {$ropaPivotInserts} pivot rows ({$ropaSkipped} ROPAs skipped — no sistem_terkait)");
        $this->info("DPIA→ROPA: {$dpiaPivotInserts} pivot rows ({$dpiaSkipped} DPIAs skipped)");
        $this->info($dry ? 'DRY RUN complete. Re-run without --dry-run to persist.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
