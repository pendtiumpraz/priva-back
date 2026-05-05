<?php

namespace App\Console\Commands;

use App\Models\Ropa;
use Illuminate\Console\Command;

/**
 * One-time backfill: compute retention_due_date for existing RoPAs.
 * The Ropa::saving hook handles new + edited records; this fills in
 * legacy rows that haven't been touched since the hook was added.
 *
 * Run: php artisan ropa:backfill-retention-due [--dry-run]
 */
class BackfillRopaRetentionDueDate extends Command
{
    protected $signature = 'ropa:backfill-retention-due {--dry-run : Print intended writes without persisting}';

    protected $description = 'Backfill retention_due_date on existing RoPAs from retensi_list policies';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — no writes' : 'Live backfill');

        $updated = 0;
        $unchanged = 0;
        $cleared = 0;

        Ropa::query()->whereNull('deleted_at')->chunkById(200, function ($chunk) use ($dry, &$updated, &$unchanged, &$cleared) {
            foreach ($chunk as $ropa) {
                $computed = $ropa->computeRetentionDueDate();
                $current = $ropa->retention_due_date;
                $computedStr = $computed?->toDateString();
                $currentStr = $current ? (string) $current : null;

                if ($computedStr === $currentStr) {
                    $unchanged++;

                    continue;
                }

                if ($computed === null) {
                    $cleared++;
                } else {
                    $updated++;
                }

                if (! $dry) {
                    // Bypass the saving hook (it would recompute and we already have the value)
                    Ropa::withoutEvents(fn () => $ropa->forceFill(['retention_due_date' => $computedStr])->save());
                }
            }
        });

        $this->info("Updated: {$updated}");
        $this->info("Cleared (no finite duration): {$cleared}");
        $this->info("Unchanged: {$unchanged}");

        return self::SUCCESS;
    }
}
