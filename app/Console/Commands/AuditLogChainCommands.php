<?php

namespace App\Console\Commands;

use App\Services\AuditLogChainService;
use Illuminate\Console\Command;

/**
 * Artisan commands untuk manage audit log hash chain.
 *
 *   audit-logs:rebuild-chain — hash ulang semua row (idempotent).
 *     Pakai saat pertama kali enable feature, atau setelah maintenance
 *     yang sengaja modify audit data.
 *
 *   audit-logs:verify-chain  — verify chain valid dari awal.
 *     Pakai sebagai cron periodic atau on-demand audit.
 */
class AuditLogChainCommands extends Command
{
    protected $signature = 'audit-logs:chain
                            {action : rebuild | verify}
                            {--limit= : limit row untuk verify (default semua)}';

    protected $description = 'Manage hash-chain integrity di audit_logs (rebuild | verify)';

    public function handle(AuditLogChainService $service): int
    {
        $action = $this->argument('action');

        if (! $service->isEnabled() && $action !== 'rebuild') {
            $this->warn('Audit log hash chain DISABLED (security.audit_log_hash_chain_enabled=false).');
            $this->warn('Enable di /platform-admin/system-settings → Security → Platform.');
            $this->warn('Lalu run "php artisan audit-logs:chain rebuild" untuk seed hash existing rows.');
            return self::SUCCESS;
        }

        return match ($action) {
            'rebuild' => $this->doRebuild($service),
            'verify' => $this->doVerify($service),
            default => $this->invalidAction(),
        };
    }

    private function doRebuild(AuditLogChainService $service): int
    {
        $this->info('Rebuilding audit log hash chain — semua row akan di-hash ulang sesuai chain order...');
        $result = $service->rebuild();
        $this->info("Done. Updated {$result['updated']} of {$result['total']} row.");
        return self::SUCCESS;
    }

    private function doVerify(AuditLogChainService $service): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $this->info('Verifying audit log hash chain...');

        $result = $service->verify($limit);

        if ($result['valid']) {
            $this->info("✓ Chain valid. Checked {$result['checked']} of {$result['total']} row.");
            return self::SUCCESS;
        }

        $this->error("✗ Chain INVALID at row {$result['broken_at']} (created {$result['broken_at_created']})");
        $this->error("Reason: {$result['reason']}");
        $this->error("Checked {$result['checked']} of {$result['total']} row sebelum integrity break.");

        // Audit log integrity break adalah RED ALERT — log ke level warning
        // supaya muncul di alert dashboard.
        \Log::warning('Audit log chain integrity BREAK detected', $result);

        return self::FAILURE;
    }

    private function invalidAction(): int
    {
        $this->error('Action invalid. Pakai: rebuild | verify');
        return self::FAILURE;
    }
}
