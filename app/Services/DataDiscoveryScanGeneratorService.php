<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\InformationSystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Person Scan plan generator (Step 1) — AI Text-to-SQL across all systems.
 *
 * Mirrors `DataDiscoveryController::specificSearchAi` but cross-system. For
 * every InformationSystem with a populated `scan_results` schema, we send a
 * compact `[{name, columns:[name]}, ...]` slice (NEVER row data) to the LLM
 * along with a natural-language identifier prompt. The LLM returns SELECT
 * queries that we persist on `data_discovery_scan_plan_systems.table_queries`
 * — execution is a separate, user-explicit step (DataDiscoveryAppExecutor /
 * SaaS pack).
 *
 * INVARIANT: AI provider only ever sees schema metadata (table + column
 * names) and the identifier prompt. No real row data leaves the backend.
 *
 * Defense in depth — destructive verbs are regex-rejected before persisting,
 * the executor regex-rejects again at run time, and `DatabaseScanner`'s
 * read-only transaction rolls back any mutation that slips through both.
 */
class DataDiscoveryScanGeneratorService
{
    /** Reject any SQL containing a write/DDL verb before persisting. */
    private const DESTRUCTIVE_RE = '/\b(DELETE|UPDATE|DROP|INSERT|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|MERGE|REPLACE)\b/i';

    public function __construct(
        private DataDiscoveryMaskerService $masker = new DataDiscoveryMaskerService,
    ) {}

    /**
     * Generate a scan plan + plan_systems via AI Text-to-SQL.
     *
     * @param  array{email?:?string,name?:?string,nik?:?string,phone?:?string,dob?:?string}  $identifiers
     * @param  string[]|null  $targetSystemIds  Subset InformationSystem IDs untuk
     *                                          di-scan. Null = scan semua org user.
     *                                          Tetap di-filter org_id supaya tidak
     *                                          bisa request system milik org lain.
     */
    public function generate(string $orgId, string $userId, array $identifiers, ?array $targetSystemIds = null): DataDiscoveryScanPlan
    {
        $aiService = app(AiService::class);
        if (! $aiService->isAvailable()) {
            throw new RuntimeException('AI Provider belum dikonfigurasi.');
        }

        $normalized = $this->normalize($identifiers);
        $hashes = $this->hashIdentifiers($orgId, $normalized);
        $maskedForStorage = $this->maskIdentifiersForStorage($normalized);
        $prompt = $this->buildPrompt($normalized);

        // Iterate org's information_systems WITHOUT relying on
        // BelongsToOrg request-scope (this service may run from a worker
        // later) — explicit org_id filter via withoutGlobalScope().
        // Kalau targetSystemIds di-supply, batasi ke subset itu — tapi
        // org_id filter tetap berlaku, jadi user gak bisa request system
        // milik org lain walau punya UUID-nya.
        $query = InformationSystem::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->whereNotNull('scan_results');

        if ($targetSystemIds !== null && count($targetSystemIds) > 0) {
            $query->whereIn('id', $targetSystemIds);
        }

        $systems = $query->get();

        $plan = DataDiscoveryScanPlan::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            // Label pakai input asli (org-scoped, audit-worthy). Yang
            // ter-mask di DB cuma kolom `identifiers` JSON.
            'label' => $this->buildLabel($normalized),
            'identifiers' => $maskedForStorage,
            'identifier_hashes' => $hashes,
            'status' => DataDiscoveryScanPlan::STATUS_GENERATED,
            'total_systems' => 0,
            'total_tables' => 0,
            'skipped_tables' => 0,
            'total_hits' => 0,
            'progress' => 0,
            'expires_at' => now()->addDays((int) config('ai.history_retention_days', 30)),
        ]);

        $totalTables = 0;
        $skippedSystems = 0;
        $createdSystems = 0;

        // Source types yang DataDiscoveryAppExecutor support saat ini.
        // Non-DB sources (s3, saas, dst.) di-skip di tahap generate biar
        // tidak nyangkut sebagai 'failed' saat execute.
        $supportedTypes = ['mysql', 'mariadb', 'postgres', 'postgresql', 'pgsql'];

        foreach ($systems as $sys) {
            $sourceType = strtolower((string) ($sys->source_type ?? ''));
            if (! in_array($sourceType, $supportedTypes, true)) {
                $skippedSystems++;

                continue;
            }

            $tables = $sys->scan_results['tables'] ?? null;
            if (! is_array($tables) || empty($tables)) {
                $skippedSystems++;

                continue;
            }

            // Compact schema — only {name, columns:[name]} per table. No
            // sample values, no classification, nothing PII-adjacent. Same
            // shape as DataDiscoveryController::specificSearchAi line 502-507.
            $compactSchema = collect($tables)->map(fn ($t) => [
                'name' => $t['name'] ?? null,
                'columns' => collect($t['columns'] ?? [])
                    ->map(fn ($c) => $c['name'] ?? null)
                    ->filter()
                    ->values()
                    ->toArray(),
            ])->filter(fn ($t) => ! empty($t['name']) && ! empty($t['columns']))->values()->toArray();

            if (empty($compactSchema)) {
                $skippedSystems++;

                continue;
            }

            $aiResult = $aiService->generateSqlFromText(
                $compactSchema,
                $prompt,
                $sys->source_type ?? 'mysql',
            );

            $queries = $aiResult['sql_queries'] ?? [];
            if (! is_array($queries) || empty($queries)) {
                $skippedSystems++;

                continue;
            }

            // Defense-in-depth: reject anything destructive BEFORE persisting.
            $safeQueries = array_values(array_filter(
                $queries,
                fn ($sql) => is_string($sql) && $sql !== '' && ! preg_match(self::DESTRUCTIVE_RE, $sql),
            ));

            if (empty($safeQueries)) {
                $skippedSystems++;

                continue;
            }

            $explanation = $aiResult['explanation'] ?? null;

            $tableQueries = array_map(fn (string $sql) => [
                'sql' => $sql,
                // AI generates complete SQL with literal values inline (same
                // as DataDiscoveryController::specificSearchAi). No bound
                // params — kept for shape compat with downstream consumers.
                'params' => [],
                'table' => $this->extractTableName($sql) ?? 'unknown',
                'confidence' => 'ai_generated',
                'matched_columns' => [],
                'returned_columns' => ['*'],
                'primary_key' => null,
                'ai_explanation' => $explanation,
            ], $safeQueries);

            DataDiscoveryScanPlanSystem::create([
                'org_id' => $orgId,
                'scan_plan_id' => $plan->id,
                'information_system_id' => $sys->id,
                'app_name' => $sys->name,
                'table_queries' => $tableQueries,
                'status' => DataDiscoveryScanPlanSystem::STATUS_PENDING,
            ]);

            $createdSystems++;
            $totalTables += count($tableQueries);
        }

        $plan->update([
            'total_systems' => $createdSystems,
            'total_tables' => $totalTables,
            'skipped_tables' => $skippedSystems,
        ]);

        try {
            AuditLog::create([
                'module' => 'data_discovery',
                'record_id' => $plan->id,
                'action' => 'data_discovery.scan_plan.generate',
                'user_id' => $userId,
                'changes' => [
                    'identifier_hashes' => $hashes,
                    'systems_processed' => $systems->count(),
                    'systems_skipped' => $skippedSystems,
                    'total_systems' => $createdSystems,
                    'total_tables' => $totalTables,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLog write failed in scan generate', ['err' => $e->getMessage()]);
        }

        return $plan;
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

    /**
     * Build the natural-language identifier prompt for AI Text-to-SQL.
     *
     * **Strategy (revisi v2):**
     *   - PRIMARY filter: EMAIL eksak (`LOWER(email_col) = LOWER('value')`).
     *     Email biasanya ter-index, jadi lookup O(log n) bukan O(n).
     *   - SECONDARY filter: NAMA eksak (`=`) bila diisi. JANGAN LIKE — tabel
     *     tenant client bisa ter-tabyte, `LIKE '%X%'` full-table-scan sangat
     *     mahal. NAMA dipakai sebagai pelengkap saat email tidak ditemukan
     *     atau sebagai konfirmasi tambahan, bukan filter utama.
     *   - SKIP tabel yang TIDAK punya kolom email orang (kecuali kalau nama
     *     juga diisi DAN tabel punya kolom nama eksak).
     *
     * Pakai SELECT * untuk hindari hallucinated column projection — AI
     * sering invent kolom 'email' walaupun tabel target tidak punya;
     * SELECT * + filter di frontend lebih aman.
     */
    private function buildPrompt(array $n): string
    {
        $email = $n['email'] ?? '';
        $name = $n['name'] ?? '';
        $hints = [];
        if (! empty($n['nik'])) {
            $hints[] = "NIK: \"{$n['nik']}\"";
        }
        if (! empty($n['phone'])) {
            $hints[] = "phone: \"{$n['phone']}\"";
        }
        if (! empty($n['dob'])) {
            $hints[] = "date of birth: \"{$n['dob']}\"";
        }

        $hintsLine = $hints === [] ? '' : ('Hint identifier tambahan: '.implode(', ', $hints).' (sebagai context, BUKAN filter wajib). ');

        $hasName = $name !== '';
        $nameClauseSpec = $hasName
            ? 'Bila tabel JUGA punya kolom yang terlihat sebagai kolom nama orang '
                .'(name, full_name, nama, nama_lengkap, customer_name, applicant_name, dst.), '
                .'tambahkan klausa AND dengan EQUALITY (`=`), BUKAN LIKE: '
                .'`AND LOWER(<name_col>) = LOWER(\''.$name.'\')`. '
                .'**JANGAN PERNAH pakai LIKE / ILIKE / partial match untuk kolom nama.** '
                .'Alasan: tabel client bisa berisi miliaran baris; LIKE memicu full-table-scan. '
            : 'Nama tidak disediakan — cukup filter berdasarkan email saja. ';

        return 'Cari semua baris milik orang dengan email "'.$email.'" di setiap tabel yang punya kolom email orang. '
            .'WAJIB pakai `SELECT *` — proyeksikan SEMUA kolom dari tabel apa adanya. '
            .'JANGAN sebutkan nama kolom tertentu di SELECT (hindari error "unknown column" kalau kolom tidak ada di tabel itu). '
            .'WHERE clause PRIMARY: pakai EQUALITY eksak (`=`) pada kolom email — '
            .'`LOWER(<email_col>) = LOWER(\''.$email.'\')` (MySQL/Postgres). '
            .'Identifikasi kolom email berdasarkan nama kolom yang umum: email, email_address, '
            .'mail, user_email, customer_email, applicant_email, contact_email, dst. '
            .$nameClauseSpec
            .'SKIP tabel yang tidak punya kolom email (kecuali tabel itu punya kolom nama dan nama disediakan). '
            .$hintsLine
            .'Output WAJIB SELECT only (no DELETE/UPDATE/INSERT). Batasi setiap query dengan LIMIT 100.';
    }

    /**
     * Best-effort extract of the first FROM table name from a SELECT for
     * downstream display / pack ZIP filename purposes. Returns null if the
     * regex doesn't match.
     */
    private function extractTableName(string $sql): ?string
    {
        if (preg_match('/\bFROM\s+["`]?([a-zA-Z0-9_\.]+)["`]?/i', $sql, $m)) {
            // Strip schema prefix if present (e.g. public.users → users)
            $name = $m[1];
            if (str_contains($name, '.')) {
                $name = substr($name, (int) strrpos($name, '.') + 1);
            }

            return $name;
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
