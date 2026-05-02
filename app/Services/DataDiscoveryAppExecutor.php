<?php

namespace App\Services;

use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\DataDiscoveryScanResult;
use App\Models\InformationSystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OnPrem child-job handler — executes a plan_system's AI-generated SELECT
 * queries via DatabaseScanner::executeRawReadQueries (mirrors the existing
 * `specificSearchExecute` flow), masks rows, optionally encrypts the raw
 * payload, and persists hits to data_discovery_scan_results.
 *
 * Read-only enforcement (defense in depth):
 *   1. Generator only ever persists SELECT (regex-rejects destructive verbs).
 *   2. Executor regex-rejects again before delegating to the scanner.
 *   3. DatabaseScanner runs each query inside a READ ONLY transaction with
 *      multi-statement disabled and a per-query keyword whitelist.
 *
 * NEVER throws — failed scans are recorded on the plan_system row so the
 * orchestrator can recompute parent progress without losing other apps to
 * a single bad connection.
 */
class DataDiscoveryAppExecutor
{
    private const DESTRUCTIVE_RE = '/\b(DELETE|UPDATE|DROP|INSERT|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|MERGE|REPLACE)\b/i';

    /**
     * Execute one plan_system. Returns a small summary the parent orchestrator
     * uses to update progress. Always recomputes parent progress on exit.
     *
     * @return array{hits:int, tokens:int, status:string, error?:string}
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
            $sys = InformationSystem::query()
                ->withoutGlobalScope('org')
                ->find($ps->information_system_id);
            if (! $sys) {
                throw new RuntimeException('InformationSystem not found');
            }
            if ($sys->org_id !== $ps->org_id) {
                throw new RuntimeException('Cross-tenant access blocked');
            }

            $tableQueries = $ps->table_queries ?? [];
            if (empty($tableQueries)) {
                $ps->update([
                    'status' => DataDiscoveryScanPlanSystem::STATUS_DONE,
                    'hit_count' => 0,
                    'finished_at' => now(),
                    'error' => null,
                ]);
                $this->recomputeParentProgress($ps->scan_plan_id);

                return ['hits' => 0, 'tokens' => 0, 'status' => 'done'];
            }

            // Extract SQL strings + reject destructive verbs (defense in depth).
            $queries = [];
            foreach ($tableQueries as $q) {
                $sql = (string) ($q['sql'] ?? '');
                if ($sql === '') {
                    continue;
                }
                if (preg_match(self::DESTRUCTIVE_RE, $sql)) {
                    throw new RuntimeException('Destructive SQL detected, refusing to execute.');
                }
                $queries[] = $sql;
            }

            if (empty($queries)) {
                $ps->update([
                    'status' => DataDiscoveryScanPlanSystem::STATUS_DONE,
                    'hit_count' => 0,
                    'finished_at' => now(),
                    'error' => null,
                ]);
                $this->recomputeParentProgress($ps->scan_plan_id);

                return ['hits' => 0, 'tokens' => 0, 'status' => 'done'];
            }

            $config = $sys->connection_config ?? [];
            $sourceType = $this->normalizeSourceType($sys->source_type ?? ($config['driver'] ?? ''));
            if ($sourceType === null) {
                throw new RuntimeException("Source type '{$sys->source_type}' not supported in MVP.");
            }

            // Delegate execution to the same hardened helper used by
            // DataDiscoveryController::specificSearchExecute (line 557).
            $execResults = DatabaseScanner::executeRawReadQueries($sourceType, $config, $queries);
            if (isset($execResults['error'])) {
                throw new RuntimeException('DB execution failed: '.$execResults['error']);
            }

            $columnClassifications = $this->columnClassificationsForSystem($sys);
            $mode = config('ai.deployment_mode', 'saas');

            $hits = 0;
            foreach ($execResults['results'] ?? [] as $idx => $res) {
                $rows = $res['rows'] ?? [];
                if (empty($rows)) {
                    continue;
                }

                $tableQuery = $tableQueries[$idx] ?? [];
                $tableName = (string) ($tableQuery['table'] ?? 'unknown');
                $matched = (array) ($tableQuery['matched_columns'] ?? []);
                $confidence = (string) ($tableQuery['confidence'] ?? 'ai_generated');

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $masked = $this->maskRow($row, $columnClassifications);
                    $rowPks = $this->extractPks($row);

                    $resultData = [
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
                        'revealed' => false,
                    ];

                    if ($mode === 'onprem') {
                        try {
                            $resultData['encrypted_row'] = Crypt::encryptString(
                                json_encode($row, JSON_UNESCAPED_UNICODE),
                            );
                        } catch (\Throwable $e) {
                            Log::warning('Crypt::encryptString failed', ['err' => $e->getMessage()]);
                        }
                    }

                    DataDiscoveryScanResult::create($resultData);
                    $hits++;
                }
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
            $msg = $e->getMessage();
            // Connection-level errors (host blocked, refused, timeout, auth
            // denied, source type unsupported) → mark 'skipped' supaya app
            // lain tetap diproses dan plan keseluruhan tetap completed.
            // SQL-level errors (column not found, syntax) → tetap 'failed'
            // karena itu indikasi bug AI generate atau schema drift yang
            // perlu di-surface ke user.
            $isConnectionLevel = $this->isConnectionLevelError($msg);
            $newStatus = $isConnectionLevel
                ? DataDiscoveryScanPlanSystem::STATUS_SKIPPED
                : DataDiscoveryScanPlanSystem::STATUS_FAILED;

            Log::warning('DataDiscoveryAppExecutor '.($isConnectionLevel ? 'skipped' : 'failed'), [
                'plan_system_id' => $ps->id,
                'org_id' => $ps->org_id,
                'error' => $msg,
            ]);
            $ps->update([
                'status' => $newStatus,
                'error' => substr($msg, 0, 1024),
                'finished_at' => now(),
            ]);
            $this->recomputeParentProgress($ps->scan_plan_id);

            return [
                'hits' => 0,
                'tokens' => 0,
                'status' => $isConnectionLevel ? 'skipped' : 'failed',
                'error' => $msg,
            ];
        }
    }

    /**
     * Heuristic: apakah error ini connection-level (env/infra issue) atau
     * SQL-level (bug/schema issue)? Connection-level → skipped supaya
     * scan lain lanjut.
     */
    private function isConnectionLevelError(string $msg): bool
    {
        $patterns = [
            'is blocked because of many connection errors',  // MySQL host block
            'mysqladmin flush-hosts',
            'Connection refused',
            'Connection timed out',
            'timeout exceeded',
            "Can't connect to",
            'Lost connection',
            'MySQL server has gone away',
            'Server has gone away',
            'SSL connection error',
            'Access denied for user',
            'Authentication failed',
            'Unknown database',
            'Unknown MySQL server host',
            'No route to host',
            'Network is unreachable',
            'not supported in MVP',  // Source type unsupported
            'getaddrinfo',
            'could not translate host name',  // Postgres unreachable
            'Operation timed out',
        ];
        foreach ($patterns as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Masking
    // =========================================================================

    /**
     * Mask a row using either the schema's column→classification map (when
     * present) or a column-name-based heuristic (fallback for AI-generated
     * SQL that may project columns not in the original scan_results).
     */
    private function maskRow(array $row, array $columnClassifications): array
    {
        $masked = [];
        foreach ($row as $col => $val) {
            $cls = $columnClassifications[$col] ?? $this->guessClassification((string) $col);
            $masked[$col] = $cls
                ? DataDiscoveryMaskerService::mask($val, $cls)
                : $val;
        }

        return $masked;
    }

    /**
     * Heuristic column→classification guess for AI-projected columns whose
     * name isn't in the scan_results map. Conservative — returns null when
     * unsure so the value passes through clear.
     */
    private function guessClassification(string $columnName): ?string
    {
        $col = strtolower($columnName);
        if (str_contains($col, 'email')) {
            return 'email';
        }
        if (str_contains($col, 'phone') || str_contains($col, 'mobile') || str_contains($col, 'hp')) {
            return 'phone';
        }
        if (str_contains($col, 'nik') || str_contains($col, 'national_id') || str_contains($col, 'identity_number')) {
            return 'nik';
        }
        if (str_contains($col, 'npwp') || str_contains($col, 'tax_id')) {
            return 'npwp';
        }
        if (str_contains($col, 'card') || str_contains($col, 'kartu')) {
            return 'credit_card';
        }
        if (str_contains($col, 'account') || str_contains($col, 'rekening')) {
            return 'account_number';
        }
        if (str_contains($col, 'passport')) {
            return 'passport';
        }
        if (str_contains($col, 'address') || str_contains($col, 'alamat')) {
            return 'address';
        }
        if (str_contains($col, 'dob') || str_contains($col, 'birth')) {
            return 'dob';
        }
        if (str_contains($col, 'name') || str_contains($col, 'nama')) {
            return 'name';
        }

        return null;
    }

    /**
     * Extract a primary-key snapshot from a returned row. Prefers `id` if
     * present, otherwise falls back to the first column. Used by the Reveal
     * UI to identify the source row.
     */
    private function extractPks(array $row): array
    {
        if (array_key_exists('id', $row)) {
            return [['id' => $row['id']]];
        }
        $first = array_key_first($row);
        if ($first === null) {
            return [];
        }

        return [[$first => $row[$first]]];
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

    /**
     * Normalize the InformationSystem.source_type alias to the keys
     * DatabaseScanner::executeRawReadQueries expects (`mysql` | `postgresql`).
     */
    private function normalizeSourceType(string $sourceType): ?string
    {
        return match (strtolower($sourceType)) {
            'mysql', 'mariadb' => 'mysql',
            'postgres', 'postgresql', 'pgsql' => 'postgresql',
            default => null,
        };
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
            $allFailed = $children->where('status', DataDiscoveryScanPlanSystem::STATUS_FAILED)->count() === $total;
            $status = $allFailed
                ? DataDiscoveryScanPlan::STATUS_FAILED
                : DataDiscoveryScanPlan::STATUS_COMPLETED;
            $progress = 100;
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
