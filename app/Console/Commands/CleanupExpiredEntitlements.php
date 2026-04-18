<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TenantModuleEntitlement;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Marks tenant_module_entitlements with valid_until in the past as not entitled.
 * MenuRegistryService already does this runtime check, so this command is for
 * database hygiene / audit trail completeness.
 *
 *   php artisan entitlements:cleanup-expired
 *
 * Registered to run daily at 02:00 in Console/Kernel.php.
 */
class CleanupExpiredEntitlements extends Command
{
    protected $signature = 'entitlements:cleanup-expired {--dry-run : Report what would change without writing}';
    protected $description = 'Disable tenant entitlements whose valid_until has passed';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $expired = TenantModuleEntitlement::whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now())
            ->where('is_entitled', true)
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired entitlements to revoke. ✓');
            return self::SUCCESS;
        }

        $this->info("Found {$expired->count()} expired entitlements" . ($dryRun ? ' (dry-run)' : ''));

        foreach ($expired as $e) {
            $this->line("  → org={$e->org_id} menu={$e->menu_id} expired={$e->valid_until->toDateString()}");
            if (!$dryRun) {
                $e->update(['is_entitled' => false]);
                try {
                    AuditLog::create([
                        'id' => (string) Str::uuid(),
                        'module' => 'menu_registry',
                        'record_id' => $e->id,
                        'action' => 'entitlement_auto_revoked',
                        'user_id' => null,
                        'user_name' => 'system-cron',
                        'user_role' => 'system',
                        'section' => 'auto_revoke',
                        'changes' => [
                            'org_id' => $e->org_id,
                            'menu_id' => $e->menu_id,
                            'expired_at' => $e->valid_until->toIso8601String(),
                        ],
                    ]);
                } catch (\Throwable $ex) {
                    \Log::warning('Audit log for auto-revoke failed: ' . $ex->getMessage());
                }
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
