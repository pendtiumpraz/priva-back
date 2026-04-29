<?php

namespace App\Console\Commands;

use App\Jobs\MigrateTenantDataJob;
use App\Models\DatabasePool;
use App\Models\Organization;
use App\Services\TenantDb\DatabasePoolRegistry;
use App\Services\TenantDb\PrivasimuHostedProvisioner;
use App\Services\TenantDb\TenantDatabaseService;
use App\Services\TenantDb\TenantDataMigrator;
use Illuminate\Console\Command;

/**
 * Upgrade an existing tenant from shared landlord DB to a dedicated DB
 * in a registered pool. Triggers the full migration pipeline:
 * provision → migrate schema → freeze → copy data → verify → cutover.
 *
 * Usage:
 *   php artisan tenants:migrate-data <org-id> --pool=<id|name>
 *   php artisan tenants:migrate-data <org-id> --pool=<id> --sync
 *
 * --sync runs in-process so errors print directly. Without it the job
 * goes through the queue.
 *
 * For brand-new tenants where there's no data to copy, use
 * `tenants:provision` instead — it skips the freeze + copy phases.
 */
class TenantsMigrateData extends Command
{
    protected $signature = 'tenants:migrate-data
        {org : Organization id (UUID)}
        {--pool= : Database pool id or name}
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Upgrade an existing tenant from shared landlord DB to a dedicated pool DB';

    public function handle(): int
    {
        $orgId = (string) $this->argument('org');
        $org = Organization::query()->find($orgId);
        if (!$org) {
            $this->error("Organization {$orgId} not found.");
            return self::FAILURE;
        }

        $pool = $this->resolvePool($this->option('pool'));
        if (!$pool) {
            $this->error('No suitable database pool found. Pass --pool=<id|name> or create an active pool first.');
            return self::FAILURE;
        }

        $this->info("Org: {$org->id} ({$org->name})");
        $this->info("Pool: {$pool->name} ({$pool->engine} @ {$pool->host}:{$pool->port})");
        $this->info("Current state: {$org->tenant_db_state}");

        if ($org->tenant_db_state !== 'shared') {
            $this->warn("Tenant is not in 'shared' state. Migration only supports shared→isolated upgrades.");
            return self::FAILURE;
        }

        $this->warn("⚠ This will:");
        $this->warn("  1. Provision a dedicated DB on pool {$pool->name}");
        $this->warn("  2. Run schema migrations on the new DB");
        $this->warn("  3. FREEZE WRITES on tenant {$org->name} (read-only mode for users)");
        $this->warn("  4. Copy all tenant data shared → tenant DB (chunked)");
        $this->warn("  5. Verify row counts match");
        $this->warn("  6. Atomic cutover to isolated state");
        $this->warn("  Tenant data in shared landlord remains until grace-period cleanup (default 7 days).");

        if (!$this->confirm('Proceed?', false)) {
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->info('Running synchronously. This may take a while for large tenants...');
            (new MigrateTenantDataJob($org->id, $pool->id))->handle(
                app(PrivasimuHostedProvisioner::class),
                app(DatabasePoolRegistry::class),
                app(TenantDatabaseService::class),
                app(TenantDataMigrator::class),
            );
            $this->info('Migration complete. Tenant is now isolated.');
        } else {
            MigrateTenantDataJob::dispatch($org->id, $pool->id);
            $this->info('Job dispatched. Watch queue worker logs.');
        }

        return self::SUCCESS;
    }

    private function resolvePool(?string $hint): ?DatabasePool
    {
        if (!$hint) {
            return app(DatabasePoolRegistry::class)->findActivePool();
        }
        return DatabasePool::query()->where('id', $hint)->orWhere('name', $hint)->first();
    }
}
