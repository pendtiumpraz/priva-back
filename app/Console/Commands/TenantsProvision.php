<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTenantDatabaseJob;
use App\Models\DatabasePool;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Manual trigger for tenant database provisioning. Mostly for ops smoke-
 * testing and emergency recovery; the production flow is the change-request
 * approval queue calling ProvisionTenantDatabaseJob through the job layer.
 *
 * Usage:
 *   php artisan tenants:provision <org-id> --pool=<pool-id-or-name>
 *   php artisan tenants:provision <org-id> --pool=<id> --sync   # run inline
 *
 * Without --sync, dispatches to the queue and returns immediately. With
 * --sync, runs the job in-process so you can see errors directly.
 */
class TenantsProvision extends Command
{
    protected $signature = 'tenants:provision
        {org : Organization id (UUID)}
        {--pool= : Database pool id or name (defaults to first active pool with capacity)}
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Provision a dedicated tenant database in a registered pool';

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

        if ($org->tenant_db_state === 'isolated') {
            $this->warn('Already isolated. Aborting.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Provision now?', true)) {
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->info('Running synchronously...');
            (new ProvisionTenantDatabaseJob($org->id, $pool->id))->handle(
                app(\App\Services\TenantDb\PrivasimuHostedProvisioner::class),
                app(\App\Services\TenantDb\DatabasePoolRegistry::class),
                app(\App\Services\TenantDb\TenantDatabaseService::class),
            );
            $this->info('Done. Org is now isolated.');
        } else {
            ProvisionTenantDatabaseJob::dispatch($org->id, $pool->id);
            $this->info('Job dispatched to queue. Watch the queue worker logs.');
        }

        return self::SUCCESS;
    }

    private function resolvePool(?string $hint): ?DatabasePool
    {
        if (!$hint) {
            return app(\App\Services\TenantDb\DatabasePoolRegistry::class)->findActivePool();
        }

        return DatabasePool::query()
            ->where('id', $hint)
            ->orWhere('name', $hint)
            ->first();
    }
}
