<?php

namespace App\Services;

use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\DataDiscoveryScanResult;
use App\Models\InformationSystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * OnPrem child-job handler — actually executes the plan_system's SELECT
 * queries against the tenant DB connection, masks results, encrypts the raw
 * row, and persists hits to data_discovery_scan_results.
 *
 * Read-only enforcement (defense in depth):
 *   1. Generator only ever emits SELECT (whitelist regex on column names).
 *   2. Executor regex-rejects any query containing destructive verbs.
 *   3. Connection level — MySQL: SET SESSION TRANSACTION READ ONLY;
 *      PostgreSQL: SET default_transaction_read_only = on;
 *
 * NEVER throws — failed scans are recorded on the plan_system row so the
 * orchestrator can recompute parent progress without losing other apps to
 * a single bad connection.
 */
class DataDiscoveryAppExecutor
{
    private const DESTRUCTIVE_RE = '/\b(DELETE|UPDATE|DROP|INSERT|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|MERGE|REPLACE)\b/i';

    /** Per-row execution timeout (seconds). */
    private const QUERY_TIMEOUT_S = 30;

    /**
     * Execute one plan_system. Returns a small summary the parent orchestrator
     * uses to update progress. Always recomputes parent progress on exit.
     *
     * @return array{hits:int, tokens:int, status:string}
     */
    public function execute(string $planSystemId): array
    {
        $ps = DataDiscoveryScanPlanSystem::query()->find($planSystemId);
        if (! $ps) {
            // Row hard-deleted between dispatch and pickup — nothing to do.
            return ['hits' => 0, 'tokens' => 0, 'status' => 'missing'];
        }

        $ps->update([
            'status' => DataDiscoveryScanPlanSystem::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $sys = InformationSystem::query()->withoutGlobalScope('org')->find($ps->information_system_id);
            if (! $sys) {
                throw new \RuntimeException('InformationSystem not found');
            }
            if ($sys->org_id !== $ps->org_id) {
                throw new \RuntimeException('Cross-tenant access blocked');
            }

            $columnClassifications = $this->columnClassificationsForSystem($sys);

            $pdo = $this->openReadOnlyPdo($sys);
            $hits = 0;
            foreach (($ps->table_queries ?? []) as $q) {
                $hits += $this->runOneQuery($pdo, $ps, $q, $sys, $columnClassifications);
            }

            $ps->update([
                'status' => DataDiscoveryScanPlanSystem::STATUS_DONE,
                'hit_count' => $hits,
                'finished_at' => now(),
                'error' => null,
            ]);

            $this->recomputeParentProgress($ps->scan_plan_id);

            return ['hits' => $hits, 'tokens' => 0, 'status' => 'done'];
        } catch (\Throwable $e) {
            Log::warning('DataDiscoveryAppExecutor failed', [
                'plan_system_id' => $ps->id,
                'org_id' => $ps->org_id,
                'error' => $e->getMessage(),
            ]);
            $ps->update([
                'status' => DataDiscoveryScanPlanSystem::STATUS_FAILED,
                'error' => substr($e->getMessage(), 0, 1024),
                'finished_at' => now(),
            ]);
            $this->recomputeParentProgress($ps->scan_plan_id);

            return ['hits' => 0, 'tokens' => 0, 'status' => 'failed'];
        }
    }

    // =========================================================================
    // Query execution
    // =========================================================================

    private function runOneQuery(PDO $pdo, DataDiscoveryScanPlanSystem $ps, array $q, InformationSystem $sys, array $columnClassifications): int
    {
        $sql = (string) ($q['sql'] ?? '');
        $params = $q['params'] ?? [];
        if ($sql === '' || preg_match(self::DESTRUCTIVE_RE, $sql)) {
            // Refuse to execute anything that smells like a write/DDL.
            return 0;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));

        $hits = 0;
        $pk = $q['primary_key'] ?? null;
        $matched = (array) ($q['matched_columns'] ?? []);
        $confidence = (string) ($q['confidence'] ?? 'medium');
        $tableName = (string) ($q['table'] ?? 'unknown');

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false && $row !== null) {
            $rowPks = $pk && array_key_exists($pk, $row) ? [[$pk => $row[$pk]]] : [];
            $masked = DataDiscoveryMaskerService::maskRow($row, $columnClassifications);
            $encrypted = null;
            try {
                $encrypted = Crypt::encryptString(json_encode($row, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                Log::warning('Crypt::encryptString failed', ['err' => $e->getMessage()]);
            }

            DataDiscoveryScanResult::create([
                'org_id' => $ps->org_id,
                'scan_plan_id' => $ps->scan_plan_id,
                'plan_system_id' => $ps->id,
                'information_system_id' => $ps->information_system_id,
                'table_name' => $tableName,
                'confidence' => $confidence,
                'matched_columns' => $matched,
                'match_count' => 1,
                'row_pks' => $rowPks,
                'masked_row' => $masked,
                'encrypted_row' => $encrypted,
                'revealed' => false,
            ]);
            $hits++;
        }

        return $hits;
    }

    /**
     * Build a {column_name => classification} map from the IS scan_results so
     * the masker can pick the right rule per column.
     */
    private function columnClassificationsForSystem(InformationSystem $sys): array
    {
        $map = [];
        $tables = $sys->scan_results['tables'] ?? [];
        foreach ($tables as $t) {
            foreach (($t['columns'] ?? []) as $c) {
                $name = $c['name'] ?? null;
                $cls = $c['classification'] ?? null;
                if ($name && $cls) {
                    // last write wins — same column name across tables expected
                    // to share a classification within one IS
                    $map[$name] = $cls;
                }
            }
        }

        return $map;
    }

    // =========================================================================
    // Connection
    // =========================================================================

    /**
     * Open a PDO connection from InformationSystem.connection_config and
     * lock it to read-only at the session level.
     */
    private function openReadOnlyPdo(InformationSystem $sys): PDO
    {
        $cfg = $sys->connection_config ?? [];
        if (! is_array($cfg) || empty($cfg)) {
            throw new \RuntimeException('InformationSystem has no connection_config');
        }
        $sourceType = strtolower((string) ($sys->source_type ?? $cfg['driver'] ?? ''));
        $driver = match ($sourceType) {
            'mysql', 'mariadb' => 'mysql',
            'postgres', 'postgresql', 'pgsql' => 'pgsql',
            default => null,
        };
        if ($driver === null) {
            throw new \RuntimeException("Source type '{$sourceType}' not supported in MVP. Only mysql/postgres scans currently implemented.");
        }

        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? ($driver === 'mysql' ? 3306 : 5432);
        $dbname = $cfg['database'] ?? $cfg['dbname'] ?? null;
        $username = $cfg['username'] ?? $cfg['user'] ?? null;
        $password = $cfg['password'] ?? '';

        if (! $dbname || ! $username) {
            throw new \RuntimeException('InformationSystem connection_config missing database/username');
        }

        $dsn = $driver === 'mysql'
            ? "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4"
            : "pgsql:host={$host};port={$port};dbname={$dbname}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => self::QUERY_TIMEOUT_S,
        ];

        $pdo = new PDO($dsn, $username, $password, $options);

        // Read-only enforcement at the connection level.
        if ($driver === 'mysql') {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } else {
            $pdo->exec('SET default_transaction_read_only = on');
        }

        return $pdo;
    }

    // =========================================================================
    // Parent progress
    // =========================================================================

    /**
     * Recompute the parent plan's progress + status from the children. Called
     * after every child completes (success OR failure) so progress is always
     * monotonically correct.
     */
    private function recomputeParentProgress(string $scanPlanId): void
    {
        $plan = DataDiscoveryScanPlan::find($scanPlanId);
        if (! $plan) {
            return;
        }

        $children = DataDiscoveryScanPlanSystem::where('scan_plan_id', $scanPlanId)->get();
        if ($children->isEmpty()) {
            return;
        }

        $total = $children->count();
        $terminal = $children->whereIn('status', [
            DataDiscoveryScanPlanSystem::STATUS_DONE,
            DataDiscoveryScanPlanSystem::STATUS_FAILED,
            DataDiscoveryScanPlanSystem::STATUS_SKIPPED,
        ])->count();
        $hits = (int) $children->sum('hit_count');

        $progress = (int) floor(($terminal / $total) * 100);
        $status = $plan->status;
        if ($terminal >= $total) {
            $hasFail = $children->where('status', DataDiscoveryScanPlanSystem::STATUS_FAILED)->isNotEmpty();
            $allFailed = $children->where('status', DataDiscoveryScanPlanSystem::STATUS_FAILED)->count() === $total;
            $status = $allFailed
                ? DataDiscoveryScanPlan::STATUS_FAILED
                : DataDiscoveryScanPlan::STATUS_COMPLETED;
            $progress = 100;
            // hasFail of partial set still completes — failures preserved on rows.
            unset($hasFail);
        } elseif ($plan->status === DataDiscoveryScanPlan::STATUS_GENERATED) {
            $status = DataDiscoveryScanPlan::STATUS_EXECUTING;
        }

        $plan->update([
            'progress' => $progress,
            'total_hits' => $hits,
            'status' => $status,
        ]);

        // Don't mirror onto the parent ai_job — the parent worker terminates
        // immediately after spawning children (its job is fan-out, not wait),
        // so its progress is forced to 100 by ProcessAiJob::handle(). The
        // canonical source of truth for live UI progress is the plan row,
        // which the frontend should poll directly via /api/data-discovery/scan/plans/{id}.
    }
}
