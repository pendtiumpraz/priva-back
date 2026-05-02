<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\InformationSystem;
use Illuminate\Support\Facades\DB;

/**
 * Person Scan plan generator (Step 1).
 *
 * INVARIANT (mirrors DsrSqlGeneratorService): Privasimu does NOT execute SQL
 * on a tenant's database during plan generation. This service only iterates
 * the schema metadata (`InformationSystem.scan_results`) and emits SELECT
 * statements that EITHER:
 *   - get bundled into a ZIP for the SaaS admin to run manually, or
 *   - get handed to DataDiscoveryAppExecutor as a child AiJob in OnPrem mode.
 *
 * Hard-coded query LIMIT (`SELF::QUERY_ROW_LIMIT`) protects every individual
 * SELECT — throughput scales via concurrency (parallel child jobs), not via
 * larger per-query result sets.
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §2 "SQL Strategy Matrix" + §5.1.
 */
class DataDiscoveryScanGeneratorService
{
    /** Hard-coded LIMIT applied to every generated SELECT — anti-runaway. */
    private const QUERY_ROW_LIMIT = 100;

    /** Whitelist regex for column / table identifiers — anti SQL injection. */
    private const IDENT_RE = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Reject any SQL that contains a write/DDL verb. Defense in depth — the
     * generator only ever emits SELECT, but we double-check before persisting
     * so a future bug can't smuggle a destructive statement through.
     */
    private const DESTRUCTIVE_RE = '/\b(DELETE|UPDATE|DROP|INSERT|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|MERGE|REPLACE)\b/i';

    public function __construct(
        private DataDiscoveryMaskerService $masker = new DataDiscoveryMaskerService,
    ) {}

    /**
     * Generate a scan plan + plan_systems for a single org / user / set of
     * identifiers. Persists everything in one transaction.
     *
     * @param  array{email:string,name:string,nik?:?string,phone?:?string,dob?:?string}  $identifiers
     */
    public function generate(string $orgId, string $userId, array $identifiers): DataDiscoveryScanPlan
    {
        $normalized = $this->normalize($identifiers);
        $hashes = $this->hashIdentifiers($orgId, $normalized);
        $maskedForStorage = $this->maskIdentifiersForStorage($normalized);

        // Iterate org's information_systems WITHOUT relying on
        // BelongsToOrg request-scope (this service may be called from a worker
        // later) — explicit org_id filter via ::query()->withoutGlobalScope().
        $systems = InformationSystem::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get();

        $totalSystems = 0;
        $totalTables = 0;
        $skippedTables = 0;
        $planSystemsToCreate = [];

        foreach ($systems as $sys) {
            $tables = $sys->scan_results['tables'] ?? [];
            if (! is_array($tables) || empty($tables)) {
                continue;
            }

            $tableQueries = [];
            foreach ($tables as $tableMeta) {
                $tableName = $tableMeta['name'] ?? null;
                if (! is_string($tableName) || ! preg_match(self::IDENT_RE, $tableName)) {
                    $skippedTables++;

                    continue;
                }
                $columns = $tableMeta['columns'] ?? [];
                if (! is_array($columns) || empty($columns)) {
                    $skippedTables++;

                    continue;
                }

                $strategy = $this->pickStrategy($columns, $normalized);
                if ($strategy === null) {
                    $skippedTables++;

                    continue;
                }

                $sql = $this->buildSelectSql($tableName, $strategy, $columns);
                if (preg_match(self::DESTRUCTIVE_RE, $sql)) {
                    // Should be unreachable — generator only emits SELECT.
                    $skippedTables++;

                    continue;
                }

                $tableQueries[] = [
                    'table' => $tableName,
                    'sql' => $sql,
                    'params' => $strategy['params'],
                    'confidence' => $strategy['confidence'],
                    'matched_columns' => $strategy['matched_columns'],
                    'returned_columns' => $strategy['returned_columns'],
                    'primary_key' => $strategy['primary_key'],
                ];
                $totalTables++;
            }

            if (empty($tableQueries)) {
                continue;
            }

            $totalSystems++;
            $planSystemsToCreate[] = [
                'information_system_id' => $sys->id,
                'app_name' => $sys->name,
                'table_queries' => $tableQueries,
            ];
        }

        $plan = DB::transaction(function () use (
            $orgId, $userId, $maskedForStorage, $hashes,
            $totalSystems, $totalTables, $skippedTables, $planSystemsToCreate
        ) {
            $plan = DataDiscoveryScanPlan::create([
                'org_id' => $orgId,
                'user_id' => $userId,
                'label' => $this->buildLabel($maskedForStorage),
                'identifiers' => $maskedForStorage,
                'identifier_hashes' => $hashes,
                'status' => DataDiscoveryScanPlan::STATUS_GENERATED,
                'total_systems' => $totalSystems,
                'total_tables' => $totalTables,
                'skipped_tables' => $skippedTables,
                'total_hits' => 0,
                'progress' => 0,
                'expires_at' => now()->addDays((int) config('ai.history_retention_days', 30)),
            ]);

            foreach ($planSystemsToCreate as $row) {
                DataDiscoveryScanPlanSystem::create([
                    'org_id' => $orgId,
                    'scan_plan_id' => $plan->id,
                    'information_system_id' => $row['information_system_id'],
                    'app_name' => $row['app_name'],
                    'table_queries' => $row['table_queries'],
                    'status' => DataDiscoveryScanPlanSystem::STATUS_PENDING,
                ]);
            }

            return $plan;
        });

        try {
            AuditLog::create([
                'module' => 'data_discovery',
                'record_id' => $plan->id,
                'action' => 'data_discovery.scan_plan.generate',
                'user_id' => $userId,
                'changes' => [
                    'identifier_hashes' => $hashes,
                    'total_systems' => $totalSystems,
                    'total_tables' => $totalTables,
                    'skipped_tables' => $skippedTables,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('AuditLog write failed in scan generate', ['err' => $e->getMessage()]);
        }

        return $plan;
    }

    /**
     * Decide which SQL strategy applies for a given table's columns + the
     * identifiers the user provided. Returns null = skip (no useful match).
     *
     * Returned shape:
     *   ['where' => string, 'params' => array, 'confidence' => 'high'|'medium',
     *    'matched_columns' => ['email','name'], 'returned_columns' => ['*'],
     *    'primary_key' => 'id'|null]
     */
    public function pickStrategy(array $columns, array $identifiers): ?array
    {
        $byClass = $this->indexColumnsByClassification($columns);
        $pk = $this->detectPrimaryKey($columns);

        $hasEmail = isset($byClass['email']) && ! empty($identifiers['email']);
        $hasName = isset($byClass['name']) && ! empty($identifiers['name']);
        $hasNik = isset($byClass['nik']) && ! empty($identifiers['nik']);
        $hasPhone = isset($byClass['phone']) && ! empty($identifiers['phone']);
        $hasDob = isset($byClass['dob']) && ! empty($identifiers['dob']);

        // Helper: pick the first column in a classification group that has a
        // safe identifier name. Returns null if none safe.
        $pickSafe = function (string $cls) use ($byClass): ?string {
            foreach ($byClass[$cls] ?? [] as $colName) {
                if (preg_match(self::IDENT_RE, (string) $colName)) {
                    return $colName;
                }
            }

            return null;
        };

        // Strategy 1: email + name + (optional nik) — high confidence
        if ($hasEmail && $hasName) {
            $emailCol = $pickSafe('email');
            $nameCol = $pickSafe('name');
            if ($emailCol && $nameCol) {
                $where = "LOWER({$emailCol}) = LOWER(?) AND LOWER({$nameCol}) = LOWER(?)";
                $params = [$identifiers['email'], $identifiers['name']];
                $matched = ['email', 'name'];
                if ($hasNik && ($nikCol = $pickSafe('nik'))) {
                    $where .= " AND {$nikCol} = ?";
                    $params[] = $identifiers['nik'];
                    $matched[] = 'nik';
                }

                return [
                    'where' => $where,
                    'params' => $params,
                    'confidence' => 'high',
                    'matched_columns' => $matched,
                    'returned_columns' => ['*'],
                    'primary_key' => $pk,
                ];
            }
        }

        // Strategy 2: email alone — high confidence
        if ($hasEmail && ($emailCol = $pickSafe('email'))) {
            return [
                'where' => "LOWER({$emailCol}) = LOWER(?)",
                'params' => [$identifiers['email']],
                'confidence' => 'high',
                'matched_columns' => ['email'],
                'returned_columns' => ['*'],
                'primary_key' => $pk,
            ];
        }

        // Strategy 3: NIK alone — high confidence
        if ($hasNik && ($nikCol = $pickSafe('nik'))) {
            return [
                'where' => "{$nikCol} = ?",
                'params' => [$identifiers['nik']],
                'confidence' => 'high',
                'matched_columns' => ['nik'],
                'returned_columns' => ['*'],
                'primary_key' => $pk,
            ];
        }

        // Strategy 4: phone + name — medium confidence
        if ($hasPhone && $hasName) {
            $phoneCol = $pickSafe('phone');
            $nameCol = $pickSafe('name');
            if ($phoneCol && $nameCol) {
                return [
                    'where' => "{$phoneCol} = ? AND LOWER({$nameCol}) = LOWER(?)",
                    'params' => [$identifiers['phone'], $identifiers['name']],
                    'confidence' => 'medium',
                    'matched_columns' => ['phone', 'name'],
                    'returned_columns' => ['*'],
                    'primary_key' => $pk,
                ];
            }
        }

        // Strategy 5: name + dob — medium confidence
        if ($hasName && $hasDob) {
            $nameCol = $pickSafe('name');
            $dobCol = $pickSafe('dob');
            if ($nameCol && $dobCol) {
                return [
                    'where' => "LOWER({$nameCol}) = LOWER(?) AND {$dobCol} = ?",
                    'params' => [$identifiers['name'], $identifiers['dob']],
                    'confidence' => 'medium',
                    'matched_columns' => ['name', 'dob'],
                    'returned_columns' => ['*'],
                    'primary_key' => $pk,
                ];
            }
        }

        // Name-only / no matching PII column → skip (per plan §2).
        return null;
    }

    /**
     * Normalize identifiers per plan §2:
     *   - email/name → trim, collapse whitespace, lowercase
     *   - nik/phone  → digit-only
     *   - dob        → keep as YYYY-MM-DD
     */
    public function normalize(array $identifiers): array
    {
        $email = $this->collapseWs((string) ($identifiers['email'] ?? ''));
        $name = $this->collapseWs((string) ($identifiers['name'] ?? ''));
        $nik = $identifiers['nik'] ?? null;
        $phone = $identifiers['phone'] ?? null;
        $dob = $identifiers['dob'] ?? null;

        return [
            'email' => $email !== '' ? mb_strtolower($email) : '',
            'name' => $name !== '' ? mb_strtolower($name) : '',
            'nik' => $nik ? preg_replace('/\D/', '', (string) $nik) : null,
            'phone' => $phone ? preg_replace('/\D/', '', (string) $phone) : null,
            'dob' => $dob ? trim((string) $dob) : null,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function buildSelectSql(string $tableName, array $strategy, array $columns): string
    {
        // Validate identifier whitelist already happened in pickStrategy — this
        // is the final assembly. We cap with LIMIT to prevent runaway scans.
        return sprintf(
            'SELECT * FROM %s WHERE %s LIMIT %d',
            $tableName,
            $strategy['where'],
            self::QUERY_ROW_LIMIT,
        );
    }

    /**
     * Build classification → [columnName, …] index from scan_results columns.
     * Skips columns whose name fails the identifier whitelist regex.
     */
    private function indexColumnsByClassification(array $columns): array
    {
        $byClass = [];
        foreach ($columns as $col) {
            $name = $col['name'] ?? null;
            $cls = strtolower((string) ($col['classification'] ?? ''));
            if (! is_string($name) || $cls === '') {
                continue;
            }
            if (! preg_match(self::IDENT_RE, $name)) {
                continue;
            }

            // Normalize plan-aliases. e.g. mobile → phone, full_name → name
            $key = match ($cls) {
                'mobile', 'phone_number' => 'phone',
                'full_name', 'first_name', 'last_name' => 'name',
                'national_id', 'identity_number' => 'nik',
                'birth_date', 'date_of_birth' => 'dob',
                default => $cls,
            };
            $byClass[$key][] = $name;
        }

        return $byClass;
    }

    /**
     * Pick the table's primary key from scan_results metadata if present.
     * Falls back to a column literally named "id" (must pass whitelist).
     */
    private function detectPrimaryKey(array $columns): ?string
    {
        foreach ($columns as $col) {
            $name = $col['name'] ?? null;
            if (! is_string($name) || ! preg_match(self::IDENT_RE, $name)) {
                continue;
            }
            if (! empty($col['is_primary_key']) || ! empty($col['primary_key'])) {
                return $name;
            }
        }
        foreach ($columns as $col) {
            if (($col['name'] ?? null) === 'id') {
                return 'id';
            }
        }

        return null;
    }

    private function collapseWs(string $s): string
    {
        $s = trim($s);

        return preg_replace('/\s+/', ' ', $s) ?? '';
    }

    /**
     * Mask identifiers before persisting to the plan row. The plan record is
     * tenant-readable so the values themselves shouldn't be a fresh PII risk.
     */
    private function maskIdentifiersForStorage(array $normalized): array
    {
        return [
            'email' => $normalized['email'] !== ''
                ? DataDiscoveryMaskerService::mask($normalized['email'], 'email') : null,
            'name' => $normalized['name'] !== ''
                ? DataDiscoveryMaskerService::mask($normalized['name'], 'name') : null,
            'nik' => $normalized['nik']
                ? DataDiscoveryMaskerService::mask($normalized['nik'], 'nik') : null,
            'phone' => $normalized['phone']
                ? DataDiscoveryMaskerService::mask($normalized['phone'], 'phone') : null,
            'dob' => $normalized['dob']
                ? DataDiscoveryMaskerService::mask($normalized['dob'], 'dob') : null,
        ];
    }

    /**
     * Per-org salted hashes for audit/dedup. Salting with org_id avoids a
     * cross-tenant rainbow-table on any leaked plan rows.
     */
    private function hashIdentifiers(string $orgId, array $normalized): array
    {
        $hash = fn (?string $v) => $v ? hash('sha256', $orgId.'|'.$v) : null;

        return [
            'email' => $hash($normalized['email'] ?: null),
            'name' => $hash($normalized['name'] ?: null),
            'nik' => $hash($normalized['nik']),
            'phone' => $hash($normalized['phone']),
            'dob' => $hash($normalized['dob']),
        ];
    }

    private function buildLabel(array $masked): string
    {
        $parts = array_filter([
            $masked['name'] ?? null,
            $masked['email'] ?? null,
        ]);

        return 'Person Scan — '.(implode(' / ', $parts) ?: 'unknown');
    }
}
