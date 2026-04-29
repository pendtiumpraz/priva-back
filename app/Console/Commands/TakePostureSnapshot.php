<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\CurrentOrgContext;
use App\Services\PostureScoreService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 3a — Daily snapshot of every active org's privacy posture.
 * Builds the trend chart that replaced rand() jitter.
 *
 * Schedule: dailyAt('05:00') — early morning Jakarta time, after most
 * scheduled scans complete (privasimu:scan-scheduled-systems runs at
 * default daily 00:00, so 5am gives 5h buffer for slow scans).
 *
 * Idempotent — running twice in the same day produces 2 rows; the
 * trend reader collapses to one-per-day automatically (latest wins).
 */
class TakePostureSnapshot extends Command
{
    protected $signature = 'privasimu:posture-snapshot {--org= : Specific org_id only}';

    protected $description = 'Snapshot privacy posture for every active organization';

    public function handle(PostureScoreService $service, CurrentOrgContext $ctx): int
    {
        $query = Organization::query()->whereNull('deleted_at');
        if ($specificOrg = $this->option('org')) {
            $query->where('id', $specificOrg);
        }

        $orgs = $query->get(['id', 'name']);
        if ($orgs->isEmpty()) {
            $this->warn('No active organizations found.');
            return self::SUCCESS;
        }

        $this->info("Taking posture snapshots for {$orgs->count()} organization(s)...");

        $ok = 0; $failed = 0;
        foreach ($orgs as $org) {
            try {
                // Set tenant context so BelongsToOrg-scoped models work correctly
                // when the service queries them via standard Eloquent paths.
                $ctx->set($org->id);
                $snap = $service->takeSnapshot($org->id);
                $this->line("  ✓ {$org->name}: score={$snap->overall_score} (data={$snap->layer_data_score}, process={$snap->layer_process_score}, response={$snap->layer_response_score})");
                $ok++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$org->name}: " . $e->getMessage());
                $failed++;
            } finally {
                $ctx->set(null);
            }
        }

        $this->info("Done. {$ok} succeeded, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
