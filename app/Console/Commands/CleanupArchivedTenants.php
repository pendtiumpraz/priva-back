<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Permanently delete archived tenants whose hard_delete_at has passed.
 * Scheduled daily at 03:00.
 *
 *   php artisan tenants:cleanup-archived [--dry-run]
 */
class CleanupArchivedTenants extends Command
{
    protected $signature = 'tenants:cleanup-archived {--dry-run : Report what would be deleted without executing}';
    protected $description = 'Hard-delete archived tenants past their retention date';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $due = Organization::onlyTrashed()
            ->where('lifecycle_status', 'archived')
            ->whereNotNull('hard_delete_at')
            ->where('hard_delete_at', '<', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No archived tenants past retention. ✓');
            return self::SUCCESS;
        }

        $this->info("Found {$due->count()} tenants due for hard delete" . ($dryRun ? ' (dry-run)' : ''));

        foreach ($due as $org) {
            $this->line("  → {$org->name} ({$org->id}) archived since {$org->offboarded_at?->toDateString()}, hard_delete_at={$org->hard_delete_at->toDateString()}");

            if (!$dryRun) {
                try {
                    AuditLog::create([
                        'id' => (string) Str::uuid(),
                        'module' => 'organization',
                        'record_id' => $org->id,
                        'action' => 'hard_deleted',
                        'user_id' => null,
                        'user_name' => 'system-cron',
                        'user_role' => 'system',
                        'section' => 'retention_expired',
                        'changes' => [
                            'org_name' => $org->name,
                            'retention_started' => $org->offboarded_at?->toIso8601String(),
                            'hard_delete_at' => $org->hard_delete_at->toIso8601String(),
                        ],
                    ]);
                } catch (\Throwable $ex) {
                    \Log::warning('Audit log for hard-delete failed: ' . $ex->getMessage());
                }

                $org->forceDelete(); // cascade handled by FK onDelete=cascade on tenant-scoped tables
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
