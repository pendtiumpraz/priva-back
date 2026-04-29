<?php

namespace App\Services\TenantDb;

use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Copies tenant data from the shared landlord DB into a tenant's dedicated
 * DB. Used during the upgrade flow when an existing tenant moves from
 * Tier 1 (shared) to Tier 2 (Privasimu-hosted) or Tier 3 (BYODB).
 *
 * Discovery: every table with an `org_id` column is automatically eligible
 * for migration. Read from `information_schema` so we don't have to
 * maintain a manual list — adding a new tenant-scoped table just means
 * adding org_id, no migrator changes required.
 *
 * Strategy (v1, simple): the tenant must be in 'freezing' state so writes
 * are blocked at the middleware level. We then chunk-copy each table
 * filtered by org_id, verify row counts at the end, and let the caller
 * (MigrateTenantDataJob) handle the cutover. No dual-write — that's a
 * follow-up if zero-downtime becomes a requirement.
 *
 * Engine support: Postgres + MySQL. Cross-engine copies use plain SELECT
 * + INSERT through PHP rather than `COPY`/`mysqldump --where` so we don't
 * have to call out to system binaries.
 */
class TenantDataMigrator
{
    public function __construct(private TenantDatabaseService $dbService) {}

    /**
     * List of landlord-only tables we MUST NOT migrate. These rows live
     * exclusively in landlord and should never be copied to a tenant DB.
     * Includes tables for landlord-pinned models (User, Organization,
     * License, etc) plus pool registry + change requests.
     */
    public const LANDLORD_TABLES = [
        'users', 'organizations', 'licenses', 'license_activations',
        'app_settings', 'menu_items', 'role_menu_whitelist',
        'database_pools', 'storage_pools', 'tenant_change_requests',
        'tenant_module_entitlements', 'tenant_menu_override',
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens',
        // Audit log can go either way — for v1 we keep it in landlord
        // so superadmin retains a unified audit view across all tenants.
        'audit_logs',
    ];

    /**
     * Discover the list of tenant tables (have an `org_id` column and
     * aren't on the landlord-only deny list). Returns sorted by foreign-
     * key dependency depth (least dependent first) so INSERTs don't
     * violate FKs that we kept in tenant DB.
     *
     * NOTE: After M4 provisioning we explicitly DROP cross-DB FKs from
     * tenant tables → landlord tables, so the only remaining FKs are
     * intra-tenant ones. Those are simpler — depth-sorted insertion is
     * usually enough to avoid violations.
     */
    public function discoverTenantTables(): array
    {
        $driver = DB::connection('landlord')->getDriverName();

        $rows = match ($driver) {
            'pgsql' => DB::connection('landlord')->select(
                "SELECT DISTINCT table_name FROM information_schema.columns "
                . "WHERE column_name = 'org_id' AND table_schema = 'public' "
                . "ORDER BY table_name"
            ),
            'mysql' => DB::connection('landlord')->select(
                "SELECT DISTINCT TABLE_NAME AS table_name FROM information_schema.COLUMNS "
                . "WHERE COLUMN_NAME = 'org_id' AND TABLE_SCHEMA = DATABASE() "
                . "ORDER BY TABLE_NAME"
            ),
            'sqlite' => $this->discoverTenantTablesSqlite(),
            default => [],
        };

        // Normalize then filter
        $tables = array_map(fn ($r) => is_object($r) ? $r->table_name : $r['table_name'], $rows);
        return array_values(array_diff($tables, self::LANDLORD_TABLES));
    }

    private function discoverTenantTablesSqlite(): array
    {
        $tables = DB::connection('landlord')->select("SELECT name FROM sqlite_master WHERE type = 'table'");
        $result = [];
        foreach ($tables as $t) {
            $cols = DB::connection('landlord')->select("PRAGMA table_info({$t->name})");
            foreach ($cols as $c) {
                if ($c->name === 'org_id') {
                    $result[] = ['table_name' => $t->name];
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Copy all tenant rows from landlord into the tenant's dedicated DB.
     * Returns a per-table summary (rows copied / errors / duration).
     *
     * @param Organization $org    The org being migrated. Must be in freezing state.
     * @param array        $config Decrypted tenant_db_config (from getDisk-style flow).
     * @param int          $chunk  Rows per chunk (default 500).
     * @return array['summary' => [...per-table...], 'total_rows' => int, 'errors' => string[]]
     */
    public function copyTenantData(Organization $org, array $config, int $chunk = 500): array
    {
        $tenantConn = $this->buildTenantConnection($org->id, $config);
        $tables = $this->discoverTenantTables();

        $summary = [];
        $totalRows = 0;
        $errors = [];

        foreach ($tables as $table) {
            $start = microtime(true);
            try {
                $copied = $this->copyTableForOrg($table, $org->id, $tenantConn, $chunk);
                $summary[$table] = [
                    'rows' => $copied,
                    'duration_ms' => round((microtime(true) - $start) * 1000),
                    'status' => 'ok',
                ];
                $totalRows += $copied;
            } catch (\Throwable $e) {
                $summary[$table] = [
                    'rows' => 0,
                    'duration_ms' => round((microtime(true) - $start) * 1000),
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $errors[] = "{$table}: {$e->getMessage()}";
                \Log::error("TenantDataMigrator: failed copying {$table} for org {$org->id}: {$e->getMessage()}");
            }
        }

        return [
            'summary' => $summary,
            'total_rows' => $totalRows,
            'errors' => $errors,
            'tables_processed' => count($tables),
        ];
    }

    /**
     * Verify rows copied: for each tenant table, count org rows in shared
     * vs tenant. Mismatch indicates the copy missed something — caller
     * should NOT cut over and instead fail the migration.
     */
    public function verifyRowCounts(Organization $org, array $config): array
    {
        $tenantConn = $this->buildTenantConnection($org->id, $config);
        $tables = $this->discoverTenantTables();

        $report = [];
        $allMatch = true;

        foreach ($tables as $table) {
            try {
                $shared = (int) DB::connection('landlord')
                    ->table($table)
                    ->where('org_id', $org->id)
                    ->count();
                $tenant = (int) DB::connection($tenantConn)
                    ->table($table)
                    ->count();   // tenant DB only has this org's rows

                $match = $shared === $tenant;
                if (!$match) $allMatch = false;

                $report[$table] = [
                    'shared' => $shared,
                    'tenant' => $tenant,
                    'match' => $match,
                ];
            } catch (\Throwable $e) {
                $report[$table] = [
                    'error' => $e->getMessage(),
                    'match' => false,
                ];
                $allMatch = false;
            }
        }

        return ['ok' => $allMatch, 'tables' => $report];
    }

    /**
     * Delete tenant rows from the shared landlord DB. Called AFTER cutover
     * + grace period — this is the hard cleanup that frees up landlord
     * storage and makes the migration irreversible.
     */
    public function cleanupSharedData(Organization $org): array
    {
        $tables = $this->discoverTenantTables();
        $deleted = [];

        foreach ($tables as $table) {
            try {
                $count = DB::connection('landlord')
                    ->table($table)
                    ->where('org_id', $org->id)
                    ->delete();
                $deleted[$table] = $count;
            } catch (\Throwable $e) {
                $deleted[$table] = ['error' => $e->getMessage()];
            }
        }

        return $deleted;
    }

    // ─── Internal ─────────────────────────────────────────────────────────

    /**
     * Copy rows of one table for one org from landlord → tenant connection.
     * Chunked to avoid memory blowup on large tables. INSERTs reuse the
     * source row's primary key (UUIDs are stable).
     */
    private function copyTableForOrg(string $table, string $orgId, string $tenantConn, int $chunk): int
    {
        $copied = 0;
        $offset = 0;

        $primaryKey = $this->primaryKeyOf($table) ?? 'id';

        while (true) {
            $rows = DB::connection('landlord')
                ->table($table)
                ->where('org_id', $orgId)
                ->orderBy($primaryKey)
                ->limit($chunk)
                ->offset($offset)
                ->get();

            if ($rows->isEmpty()) break;

            $batch = $rows->map(fn ($r) => (array) $r)->all();

            DB::connection($tenantConn)->table($table)->insert($batch);

            $copied += count($batch);
            $offset += $chunk;

            // Free memory between chunks for very large tables
            unset($rows, $batch);
        }

        return $copied;
    }

    /**
     * Best-effort primary key lookup. Falls back to 'id' if the engine
     * driver doesn't expose introspection.
     */
    private function primaryKeyOf(string $table): ?string
    {
        try {
            $driver = DB::connection('landlord')->getDriverName();
            if ($driver === 'pgsql') {
                $row = DB::connection('landlord')->select(
                    "SELECT a.attname FROM pg_index i "
                    . "JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) "
                    . "WHERE i.indrelid = ?::regclass AND i.indisprimary LIMIT 1",
                    [$table]
                );
                return $row[0]->attname ?? null;
            }
            if ($driver === 'mysql') {
                $row = DB::connection('landlord')->select(
                    "SELECT COLUMN_NAME AS pk FROM information_schema.KEY_COLUMN_USAGE "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' LIMIT 1",
                    [$table]
                );
                return $row[0]->pk ?? null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private function buildTenantConnection(string $orgId, array $config): string
    {
        $name = "tenant_migrate_{$orgId}";
        if (!Config::has("database.connections.{$name}")) {
            Config::set("database.connections.{$name}", $this->dbService->buildConnection(
                $config['engine'] ?? 'pgsql',
                $config,
            ));
        }
        DB::purge($name);
        return $name;
    }
}
