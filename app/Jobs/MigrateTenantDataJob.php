<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DatabasePool;
use App\Models\Organization;
use App\Services\TenantDb\DatabasePoolRegistry;
use App\Services\TenantDb\PrivasimuHostedProvisioner;
use App\Services\TenantDb\TenantDatabaseService;
use App\Services\TenantDb\TenantDataMigrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade an EXISTING tenant from Tier 1 (shared landlord DB) to Tier 2
 * (Privasimu-hosted dedicated DB). Differs from ProvisionTenantDatabaseJob
 * which only handles brand-new tenants where there's no data to copy.
 *
 * State machine:
 *   shared → provisioning (CREATE DATABASE + USER + GRANT)
 *          → migrating    (run schema migrations on tenant DB)
 *          → freezing     (block writes via tenant.readonly middleware)
 *          → migrating    (copy data shared → tenant)
 *          → migrating    (verify row counts)
 *          → isolated     (atomic cutover: state + config + counter)
 *
 * On failure at any phase: state='failed' with error message. The shared
 * data stays untouched until the configurable grace period elapses, so
 * a failed migration is recoverable by resetting state back to 'shared'.
 *
 * Grace-period cleanup (deletion of org rows from landlord) is a separate
 * job that runs N days after isolated_at — see CleanupMigratedTenantData.
 */
class MigrateTenantDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;   // 2 hours — large tenants can take a while

    public function __construct(public string $orgId, public string $poolId, public ?string $changeRequestId = null) {}

    public function handle(
        PrivasimuHostedProvisioner $provisioner,
        DatabasePoolRegistry $registry,
        TenantDatabaseService $dbService,
        TenantDataMigrator $migrator,
    ): void {
        $org = Organization::query()->findOrFail($this->orgId);
        $pool = DatabasePool::query()->findOrFail($this->poolId);

        if ($org->tenant_db_state === 'isolated') {
            \Log::info("MigrateTenantDataJob: org {$this->orgId} already isolated, skipping.");
            return;
        }

        if ($org->tenant_db_state !== 'shared') {
            throw new \RuntimeException("Cannot migrate from state '{$org->tenant_db_state}' — must start from 'shared'.");
        }

        try {
            // ── Phase 1: provision the dedicated DB
            $this->updateState($org, 'provisioning');
            $config = $provisioner->provision($org, $pool);

            // ── Phase 2: run schema migrations on new DB
            $this->updateState($org, 'migrating');
            $this->runMigrations($org, $config);

            // ── Phase 3: freeze writes on landlord side
            $this->updateState($org, 'freezing');

            // Brief settle window to let in-flight requests finish their
            // transactions before we start the snapshot copy.
            sleep(2);

            // ── Phase 4: copy data
            $this->updateState($org, 'migrating');
            $copyResult = $migrator->copyTenantData($org, $config);

            if (!empty($copyResult['errors'])) {
                throw new \RuntimeException(
                    "Data copy had errors: " . implode('; ', array_slice($copyResult['errors'], 0, 5))
                );
            }

            // ── Phase 5: verify row counts
            $verify = $migrator->verifyRowCounts($org, $config);
            if (!$verify['ok']) {
                $mismatches = collect($verify['tables'])
                    ->filter(fn ($r) => !($r['match'] ?? false))
                    ->keys()
                    ->take(5)
                    ->implode(', ');
                throw new \RuntimeException("Row count verification failed for tables: {$mismatches}");
            }

            // ── Phase 6: atomic cutover
            DB::transaction(function () use ($org, $pool, $config, $registry, $dbService) {
                $org->refresh();
                $org->tenant_db_provider = 'pool';
                $org->tenant_db_state = 'isolated';
                $org->tenant_db_config = $dbService->encryptConfig($config);
                $org->tenant_db_provisioned_at = $org->tenant_db_provisioned_at ?? now();
                $org->tenant_db_isolated_at = now();
                $org->tenant_db_error = null;
                $org->save();

                $registry->assignTenantToPool($org, $pool);
            });

            $this->markChangeRequest('executed', null);
            $this->audit($org, 'tenant_data_migrated', [
                'pool' => $pool->name,
                'database' => $config['database'],
                'rows_copied' => $copyResult['total_rows'],
                'tables_processed' => $copyResult['tables_processed'],
            ]);

            \Log::info("MigrateTenantDataJob: successfully migrated org {$org->id} to pool {$pool->name}. {$copyResult['total_rows']} rows across {$copyResult['tables_processed']} tables.");
        } catch (\Throwable $e) {
            \Log::error("MigrateTenantDataJob failed for org {$this->orgId}: {$e->getMessage()}", ['exception' => $e]);
            $this->updateState($org, 'failed', $e->getMessage());
            $this->markChangeRequest('failed', $e->getMessage());
            $this->audit($org, 'tenant_data_migration_failed', [
                'pool' => $pool->name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Run schema migrations on the freshly-created tenant DB. Same as
     * ProvisionTenantDatabaseJob but kept inline so the upgrade path is
     * self-contained.
     */
    private function runMigrations(Organization $org, array $config): void
    {
        $connectionName = "tenant_provision_{$org->id}";

        Config::set("database.connections.{$connectionName}", [
            'driver'   => $config['engine'],
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset'  => $config['engine'] === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix'   => '',
            'prefix_indexes' => true,
            'sslmode'  => $config['sslmode'] ?? 'prefer',
            'schema'   => 'public',
            'collation' => 'utf8mb4_unicode_ci',
            'strict'   => true,
        ]);
        DB::purge($connectionName);

        Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

        // Drop FK constraints that reference landlord-only tables
        $this->dropCrossDbForeignKeys($connectionName, $config['engine']);

        DB::purge($connectionName);
    }

    private function dropCrossDbForeignKeys(string $connectionName, string $engine): void
    {
        if ($engine !== 'pgsql') return;

        $landlordTables = [
            'users', 'organizations', 'licenses', 'license_activations',
            'app_settings', 'menu_items', 'role_menu_whitelist',
            'database_pools', 'storage_pools', 'tenant_change_requests',
        ];

        $conn = DB::connection($connectionName);
        $placeholders = implode(',', array_fill(0, count($landlordTables), '?'));
        $rows = $conn->select(
            "SELECT conname, conrelid::regclass::text AS table_name "
            . "FROM pg_constraint WHERE contype = 'f' "
            . "AND confrelid::regclass::text IN ({$placeholders})",
            $landlordTables
        );

        foreach ($rows as $row) {
            try {
                $conn->statement('ALTER TABLE "' . $row->table_name . '" DROP CONSTRAINT "' . $row->conname . '"');
            } catch (\Throwable $e) {
                \Log::warning("dropCrossDbForeignKeys: {$row->conname} on {$row->table_name}: {$e->getMessage()}");
            }
        }
    }

    private function updateState(Organization $org, string $state, ?string $error = null): void
    {
        $org->refresh();
        $org->tenant_db_state = $state;
        if ($state === 'provisioning' && !$org->tenant_db_provisioned_at) {
            $org->tenant_db_provisioned_at = now();
        }
        $org->tenant_db_error = $error;
        $org->save();
    }

    private function markChangeRequest(string $status, ?string $error): void
    {
        if (!$this->changeRequestId) return;
        try {
            \App\Models\TenantChangeRequest::query()->where('id', $this->changeRequestId)->update([
                'status' => $status,
                'executed_at' => now(),
                'error' => $error,
            ]);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    private function audit(Organization $org, string $action, array $meta): void
    {
        try {
            AuditLog::log('tenant_database', $org->id, $action, $meta, 'system');
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
