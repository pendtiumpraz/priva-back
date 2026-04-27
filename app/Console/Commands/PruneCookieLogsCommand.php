<?php

namespace App\Console\Commands;

use App\Models\CookieLog;
use Illuminate\Console\Command;

/**
 * Prune cookie_logs older than retention threshold. Default 90 days.
 *
 * Schedule:
 *   $schedule->command('consent:prune-cookie-logs')->daily()->at('02:30');
 */
class PruneCookieLogsCommand extends Command
{
    protected $signature = 'consent:prune-cookie-logs
                            {--days=90 : Retention days (rows older than this are hard-deleted)}
                            {--dry : Dry run — count only}';

    protected $description = 'Hard-delete cookie_logs rows older than retention threshold';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $query = CookieLog::query()->where('captured_at', '<', $cutoff);

        $count = $query->count();
        if ($this->option('dry')) {
            $this->info("[dry] Would prune {$count} cookie_logs older than {$cutoff->toDateTimeString()}");
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Nothing to prune.');
            return self::SUCCESS;
        }

        // Forced hard-delete: cookie_logs has soft-deletes for admin restore but
        // expired rows beyond retention should be unrecoverable per UU PDP minimization.
        $deleted = $query->forceDelete();
        $this->info("Pruned {$deleted} cookie_logs older than {$days} days.");
        return self::SUCCESS;
    }
}
