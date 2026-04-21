<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AlertEngineService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Master daily scan — runs every AlertEngine rule for every active tenant.
 * Respects the root + per-tenant notification kill switches.
 */
class ScanAllNotificationRules extends Command
{
    protected $signature = 'notifications:scan-all';
    protected $description = 'Run all AlertEngine rules for every active tenant';

    public function handle(AlertEngineService $engine): int
    {
        if (!NotificationService::isEnabled() || !NotificationService::isSchedulerEnabled()) {
            $this->info('Notifications or scheduler disabled by root — skip.');
            return self::SUCCESS;
        }

        $orgs = Organization::whereNull('deleted_at')->get();
        $total = 0;

        foreach ($orgs as $org) {
            // Per-tenant kill switch — dispatch() will no-op anyway, but
            // we skip the scan entirely to save DB work.
            if (!NotificationService::isEnabled($org->id)) {
                $this->line("  skip {$org->name} — notifications disabled for tenant");
                continue;
            }
            try {
                $new = $engine->runAllRules($org->id);
                $total += count($new);
                $this->line("  {$org->name}: " . count($new) . ' new');
            } catch (\Throwable $e) {
                $this->warn("  {$org->name}: ERROR — " . $e->getMessage());
            }
        }

        $this->info("Done. {$total} notifications created across " . $orgs->count() . ' tenants.');
        return self::SUCCESS;
    }
}
