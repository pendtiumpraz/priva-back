<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Auto-prune audit_logs lama berdasarkan retention setting.
 *
 *   php artisan audit-logs:prune              — pakai setting dari DB
 *   php artisan audit-logs:prune --days=180   — override manual
 *   php artisan audit-logs:prune --dry-run    — preview, gak hapus
 *
 * Dijalankan via scheduler (registered di Console\Kernel). Default
 * retention 0 = keep forever (command no-op kalau setting 0 tanpa flag).
 */
class PruneOldAuditLogs extends Command
{
    protected $signature = 'audit-logs:prune
                            {--days= : Override retention days (skip setting lookup)}
                            {--dry-run : Hitung baris yang akan dihapus tanpa execute}';

    protected $description = 'Prune audit_logs entries yang lebih lama dari retention setting';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('security.audit_log_retention_days', 0);

        if ($days <= 0) {
            $this->info('Audit log retention disabled (days=0). No-op.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $query = AuditLog::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("DRY RUN: would delete {$count} audit_logs entries older than {$cutoff->toIso8601String()}");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} audit_logs entries older than {$cutoff->toIso8601String()}");
        return self::SUCCESS;
    }
}
