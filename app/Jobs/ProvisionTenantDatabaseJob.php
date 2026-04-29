<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DatabasePool;
use App\Models\Organization;
use App\Services\TenantDb\DatabasePoolRegistry;
use App\Services\TenantDb\PrivasimuHostedProvisioner;
use App\Services\TenantDb\TenantDatabaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates provisioning a tenant's dedicated database in a registered
 * pool. State machine:
 *
 *   shared → provisioning → migrating → isolated
 *                       ↘                    ↘
 *                        failed (with error)  failed
 *
 * Ordering is important — we transition state BEFORE the SQL DDL so that
 * if the worker dies mid-step, a manual `tenants:provision` retry can
 * see the in-progress state and clean up first. After successful schema
 * migration, we increment the pool's tenant counter and flip the org to
 * 'isolated' atomically with the encrypted credentials.
 *
 * Skipping the data-migration step (M7) for now — this provisioner only
 * handles brand-new tenants where there's no existing data to copy from
 * the landlord shared DB. Migrating an existing tenant from shared →
 * isolated is a separate flow (dual-write window + checksum verify).
 */
class ProvisionTenantDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;            // explicit single try; retries handled at orchestration layer
    public int $timeout = 1800;       // 30 minutes — DDL is fast but migrations on large schemas can take a few

    public function __construct(public string $orgId, public string $poolId, public ?string $changeRequestId = null) {}

    public function handle(
        PrivasimuHostedProvisioner $provisioner,
        DatabasePoolRegistry $registry,
        TenantDatabaseService $dbService,
    ): void {
        $org = Organization::query()->findOrFail($this->orgId);
        $pool = DatabasePool::query()->findOrFail($this->poolId);

        if ($org->tenant_db_state === 'isolated') {
            \Log::info("Provision skipped: org {$this->orgId} already isolated.");
            return;
        }

        $this->updateState($org, 'provisioning', null);

        try {
            // Phase 1 — DDL: CREATE DATABASE + CREATE USER + GRANT
            $config = $provisioner->provision($org, $pool);

            $this->updateState($org, 'migrating', null);

            // Phase 2 — register a temporary connection pointing to the new
            // tenant DB and run schema migrations against it.
            $this->runMigrations($org, $config);

            // Phase 3 — atomic cutover: encrypt creds + flip state to isolated
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
            $this->audit($org, 'tenant_db_provisioned', ['pool' => $pool->name, 'database' => $config['database']]);
        } catch (\Throwable $e) {
            \Log::error("Provision failed for org {$this->orgId}: {$e->getMessage()}", ['exception' => $e]);
            $this->updateState($org, 'failed', $e->getMessage());
            $this->markChangeRequest('failed', $e->getMessage());
            $this->audit($org, 'tenant_db_provision_failed', ['pool' => $pool->name, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Register the freshly-created tenant DB as a Laravel connection on the
     * fly and run `php artisan migrate` against it. We use the provisioned
     * tenant credentials (not the pool's superuser) to verify the user has
     * the right grants to manage their own schema.
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

        // For now, run ALL migrations against the tenant DB. Migration
        // folder split (landlord/ vs tenant/) is M7 work; here we accept
        // empty unused tables in tenant DB for the launch. FK constraints
        // pointing to landlord-only tables (users, organizations, etc.)
        // aren't violated because no rows are inserted into those tables
        // from tenant context — landlord-pinned models query landlord DB.
        Artisan::call('migrate', [
            '--database' => $connectionName,
            '--force'    => true,
        ]);

        // Drop FK constraints that point to landlord-only tables so app-
        // level inserts on tenant tables don't fail referencing empty
        // tenant.users / tenant.organizations / etc.
        $this->dropCrossDbForeignKeys($connectionName, $config['engine']);

        DB::purge($connectionName);
    }

    /**
     * Drop FK constraints from tenant tables that reference landlord-only
     * tables (users, organizations, licenses, etc). Those tables exist in
     * tenant DB as empty leftovers from running all migrations there, but
     * they'll never be populated — landlord-pinned models always read/write
     * to landlord. Without dropping the FKs, inserting a Ropa with
     * `created_by = $userId` would fail because tenant.users is empty.
     *
     * Postgres-only for now (matches our prod target). MySQL variant has
     * a similar pattern via INFORMATION_SCHEMA.KEY_COLUMN_USAGE.
     */
    private function dropCrossDbForeignKeys(string $connectionName, string $engine): void
    {
        if ($engine !== 'pgsql') return;  // MySQL variant TODO if/when needed

        $landlordTables = [
            'users', 'organizations', 'licenses', 'license_activations',
            'app_settings', 'menu_items', 'role_menu_whitelist',
            'database_pools', 'storage_pools', 'tenant_change_requests',
        ];

        $conn = DB::connection($connectionName);

        $placeholders = implode(',', array_fill(0, count($landlordTables), '?'));
        $rows = $conn->select(
            "SELECT conname, conrelid::regclass::text AS table_name "
            . "FROM pg_constraint "
            . "WHERE contype = 'f' AND confrelid::regclass::text IN ({$placeholders})",
            $landlordTables
        );

        foreach ($rows as $row) {
            try {
                $conn->statement('ALTER TABLE "' . $row->table_name . '" DROP CONSTRAINT "' . $row->conname . '"');
            } catch (\Throwable $e) {
                \Log::warning("dropCrossDbForeignKeys: failed to drop {$row->conname} on {$row->table_name}: {$e->getMessage()}");
            }
        }
    }

    private function updateState(Organization $org, string $state, ?string $error): void
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
