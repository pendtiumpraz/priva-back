<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Services\AiSpecificSearchService\CaseResult;
use Illuminate\Support\Facades\Log;

/**
 * AiSpecificSearchService
 *
 * Menerjemahkan pertanyaan natural-language user (mis. "transaksi diatas
 * 500.000.000 hari ini") menjadi SQL SELECT-only yang spesifik terhadap
 * skema database tenant client. Service ini HANYA membangun string SQL —
 * eksekusi terhadap database client adalah tanggung jawab orchestrator
 * terpisah (Agent #7).
 *
 * Konteks yang dipakai prompt:
 *   - Industri / bidang usaha client (dari Organization::industry /
 *     business_sector / bidang_usaha — onboarding metadata).
 *   - Skema scan_results.tables[] dari InformationSystem (nama tabel +
 *     daftar kolom + tipe). Data nilai TIDAK pernah dikirim ke provider AI.
 *   - Tanggal konteks (today/yesterday/last_7d/custom) untuk filter waktu.
 *
 * Validator memastikan output AI HANYA SELECT (atau WITH ... SELECT),
 * include LIMIT ≤ 1000, dan tidak mengandung statement berbahaya
 * (DML/DDL, multi-statement, time-based attack, shell-out).
 */
class AiSpecificSearchService
{
    /**
     * Hard cap LIMIT yang diperbolehkan di SQL output.
     */
    private const MAX_LIMIT = 1000;

    public function __construct(
        private AiService $ai,
    ) {}

    /**
     * Build SELECT-only SQL untuk array of natural-language cases.
     *
     * @param  string  $orgId        Tenant org_id UUID.
     * @param  string  $systemId     InformationSystem ID yang akan di-query.
     * @param  array<int, array{id: string, query_text: string}>  $cases
     * @param  array{kind: 'today'|'yesterday'|'last_7d'|'custom', date?: string}  $dateContext
     * @return array<int, array{case_id: string, query_text: string, generated_sql: string|null, error: string|null}>
     */
    public function generateSqls(
        string $orgId,
        string $systemId,
        array $cases,
        array $dateContext,
    ): array {
        // Resolve org + system context up-front. Kalau gagal, return error
        // untuk setiap case (caller harus tahu konteks gagal di-load, bukan
        // SQL generation per-case).
        $org = Organization::find($orgId);
        if (! $org) {
            return $this->shortCircuitAll($cases, 'Organisasi tidak ditemukan untuk org_id yang diberikan.');
        }

        $system = InformationSystem::where('org_id', $orgId)->find($systemId);
        if (! $system) {
            return $this->shortCircuitAll($cases, 'Information System tidak ditemukan atau bukan milik organisasi ini.');
        }

        $industry = $this->resolveIndustry($org);
        $schemaLines = $this->buildSchemaLines($system);
        if (empty($schemaLines)) {
            return $this->shortCircuitAll(
                $cases,
                'Information System belum memiliki hasil scan skema. Jalankan scan terlebih dahulu sebelum AI Specific Search.'
            );
        }

        $resolvedDate = $this->resolveDateContext($dateContext);

        if (! $this->ai->isAvailable()) {
            return $this->shortCircuitAll($cases, 'AI Provider belum dikonfigurasi untuk organisasi ini.');
        }

        $contextBlock = $this->buildContextBlock($industry, $resolvedDate, $schemaLines);

        // Serial loop — hindari parallel supaya hemat resource (rate-limit
        // provider) dan deterministic untuk audit log.
        $results = [];
        foreach ($cases as $case) {
            $caseId = (string) ($case['id'] ?? '');
            $queryText = trim((string) ($case['query_text'] ?? ''));

            if ($caseId === '' || $queryText === '') {
                $results[] = CaseResult::error(
                    $caseId !== '' ? $caseId : '(missing-id)',
                    $queryText,
                    'Field id dan query_text wajib diisi.'
                )->toArray();
                continue;
            }

            $result = $this->generateOne($systemId, $caseId, $queryText, $contextBlock, $resolvedDate);
            $results[] = $result->toArray();

            // Audit log entry untuk case yang gagal validate — supaya
            // visibility ke superadmin/admin (mereka bisa lihat ada upaya
            // generate SQL yang AI keluarkan tapi ditolak validator).
            if ($result->error !== null) {
                try {
                    AuditLog::log(
                        module: 'data-discovery',
                        recordId: $systemId,
                        action: 'ai_specific_search_rejected',
                        changes: [
                            'case_id' => $caseId,
                            'query_text' => $queryText,
                            'error' => $result->error,
                        ],
                        section: 'ai-specific-search',
                    );
                } catch (\Throwable $e) {
                    // Audit failure jangan menggagalkan response — cukup log
                    // ke sistem log standar.
                    Log::warning('AiSpecificSearchService audit log failed: '.$e->getMessage());
                }
            }
        }

        return $results;
    }

    /**
     * Validator: reject non-SELECT statements.
     *
     * @return string|null  null jika SQL valid; string error message jika invalid.
     */
    public function validateSqlIsSelectOnly(string $sql): ?string
    {
        // Strip comments dulu supaya bypass-attack lewat comment tidak lolos.
        $stripped = $this->stripSqlComments($sql);
        $trimmed = trim($stripped);

        if ($trimmed === '') {
            return 'SQL kosong setelah strip komentar.';
        }

        // Reject explicit `--` shell escape (di luar konteks SQL line-comment
        // — comment sudah di-strip, jadi sisa `--` di sini biasanya tanda
        // injection attempt).
        if (str_contains($trimmed, '--')) {
            return 'SQL mengandung token "--" yang dilarang.';
        }

        // Multi-statement check: split by `;`, allow single trailing `;` saja.
        $withoutTrailing = rtrim($trimmed, "; \t\n\r\0\x0B");
        if (str_contains($withoutTrailing, ';')) {
            return 'SQL harus berupa satu statement tunggal (tidak boleh ada ";" di tengah).';
        }

        // Harus mulai dengan SELECT atau WITH (CTE).
        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $withoutTrailing)) {
            return 'SQL harus dimulai dengan SELECT atau WITH (CTE).';
        }

        // Reject keyword DML/DDL/privilege/admin/util berbahaya.
        $dangerousKeywords = '/\b('
            .'INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE|GRANT|REVOKE'
            .'|EXEC|EXECUTE|CALL|MERGE|REPLACE|UPSERT|COPY|ATTACH|DETACH'
            .'|VACUUM|ANALYZE'
            .'|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD\s+DATA|LOAD_FILE'
            .')\b/i';
        if (preg_match($dangerousKeywords, $withoutTrailing, $match)) {
            return 'SQL mengandung keyword terlarang: '.strtoupper($match[1]).'.';
        }

        // Reject shell-out / privileged stored procedure (SQL Server / Oracle).
        if (preg_match('/\b(xp_cmdshell|sp_executesql|sp_oacreate|dbms_[a-z_]+|utl_[a-z_]+)\b/i', $withoutTrailing, $match)) {
            return 'SQL mengandung procedure berbahaya: '.$match[1].'.';
        }

        // Reject time-based attack vectors.
        if (preg_match('/\b(pg_sleep|sleep|benchmark|waitfor\s+delay)\s*\(/i', $withoutTrailing, $match)) {
            return 'SQL mengandung fungsi time-delay yang dilarang ('.$match[1].').';
        }
        // WAITFOR DELAY without parenthesis (SQL Server).
        if (preg_match('/\bWAITFOR\s+DELAY\b/i', $withoutTrailing)) {
            return 'SQL mengandung WAITFOR DELAY yang dilarang.';
        }

        // Wajib ada LIMIT — dan LIMIT-nya ≤ MAX_LIMIT.
        if (! preg_match('/\bLIMIT\s+(\d+)\b/i', $withoutTrailing, $limitMatch)) {
            return 'SQL harus include LIMIT ≤ '.self::MAX_LIMIT.'.';
        }
        $limitValue = (int) $limitMatch[1];
        if ($limitValue < 1 || $limitValue > self::MAX_LIMIT) {
            return 'LIMIT harus berada di range 1 sampai '.self::MAX_LIMIT.' (terdeteksi: '.$limitValue.').';
        }

        return null;
    }

    // =====================================================================
    // INTERNAL HELPERS
    // =====================================================================

    /**
     * Generate SQL untuk satu kasus, panggil AI provider, validate, dan
     * return CaseResult yang siap di-serialize.
     */
    private function generateOne(
        string $systemId,
        string $caseId,
        string $queryText,
        string $contextBlock,
        string $resolvedDate,
    ): CaseResult {
        $systemPrompt =
            "Anda adalah Database Administrator ahli yang membuat query SELECT untuk pencarian data berdasarkan skema.\n"
            ."Output WAJIB berupa SATU statement SQL valid saja — tanpa markdown, tanpa penjelasan, tanpa komentar.\n"
            ."Patuhi aturan keamanan: HANYA SELECT (atau WITH ... SELECT), TIDAK ADA INSERT/UPDATE/DELETE/DROP/TRUNCATE/ALTER/CREATE/GRANT/REVOKE/EXEC/CALL/MERGE/COPY.\n";

        $userPrompt = $contextBlock
            ."\n\nKASUS: ".$queryText
            ."\n\nBuat SATU statement SQL SELECT-only untuk menjawab kasus di atas."
            ."\nATURAN WAJIB:"
            ."\n- HANYA SELECT (atau WITH ... SELECT). DILARANG INSERT/UPDATE/DELETE/DROP/TRUNCATE/ALTER."
            ."\n- WAJIB akhiri dengan LIMIT 100 (atau LIMIT lebih kecil bila relevan, maksimal LIMIT ".self::MAX_LIMIT.")."
            ."\n- Pakai tanggal: ".$resolvedDate." untuk filter \"hari ini\"/\"kemarin\"/relative-time."
            ."\n- Format tanggal: PostgreSQL style — gunakan date(col) = '".$resolvedDate."' atau col::date = '".$resolvedDate."'."
            ."\n- Output HANYA SQL, satu statement. Tanpa penjelasan, tanpa markdown ```sql, tanpa komentar."
            ."\n- Pakai nama tabel dan kolom yang TEPAT dari skema di atas. JANGAN invent kolom yang tidak ada."
            ."\n- Kalau kasus tidak bisa diterjemahkan ke SQL berdasar skema yang tersedia, jawab dengan satu kata: NOT_TRANSLATABLE";

        $response = $this->ai->ask($systemPrompt, $userPrompt, 800);

        if ($response === null) {
            return CaseResult::error($caseId, $queryText, 'AI provider tidak merespons atau gagal memproses prompt.');
        }

        // ask() berusaha extract JSON. Untuk kasus ini kita expect RAW
        // string SQL — jadi ambil dari raw fallback (atau key apapun yang
        // tersedia). Selipkan extraction yang permissive.
        $rawSql = $this->extractSqlFromAiResponse($response);
        if ($rawSql === null || $rawSql === '') {
            return CaseResult::error($caseId, $queryText, 'AI tidak mengembalikan SQL yang dapat diparsing.');
        }

        // Sinyal eksplisit dari AI: kasus tidak bisa diterjemahkan.
        if (preg_match('/\bNOT_TRANSLATABLE\b/i', $rawSql)) {
            return CaseResult::error($caseId, $queryText, 'Kasus tidak dapat diterjemahkan ke SQL dari skema yang tersedia (NOT_TRANSLATABLE).');
        }

        $cleanedSql = $this->cleanSqlString($rawSql);

        $validationError = $this->validateSqlIsSelectOnly($cleanedSql);
        if ($validationError !== null) {
            return CaseResult::error($caseId, $queryText, 'SQL invalid: '.$validationError);
        }

        return CaseResult::ok($caseId, $queryText, $cleanedSql);
    }

    /**
     * Resolve industri / bidang usaha client dari Organization.
     * Prefer field `industry`, fallback `business_sector`, fallback `bidang_usaha`,
     * fallback 'umum'.
     */
    private function resolveIndustry(Organization $org): string
    {
        foreach (['industry', 'business_sector', 'bidang_usaha'] as $field) {
            $value = $org->{$field} ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'umum';
    }

    /**
     * Build daftar skema baris-per-baris: "table.column : type".
     *
     * @return array<int, string>
     */
    private function buildSchemaLines(InformationSystem $system): array
    {
        $scan = $system->scan_results;
        if (is_string($scan)) {
            $scan = json_decode($scan, true);
        }
        if (! is_array($scan)) {
            return [];
        }

        $tables = $scan['tables'] ?? [];
        if (! is_array($tables)) {
            return [];
        }

        $lines = [];
        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }
            $tableName = (string) ($table['name'] ?? '');
            if ($tableName === '') {
                continue;
            }
            $columns = $table['columns'] ?? [];
            if (! is_array($columns)) {
                continue;
            }
            foreach ($columns as $col) {
                if (! is_array($col)) {
                    continue;
                }
                $colName = (string) ($col['name'] ?? '');
                if ($colName === '') {
                    continue;
                }
                $colType = (string) ($col['type'] ?? 'unknown');
                $lines[] = '- '.$tableName.'.'.$colName.' : '.$colType;
            }
        }

        return $lines;
    }

    /**
     * Resolve dateContext ke YYYY-MM-DD string yang dipakai di prompt.
     *
     * @param  array{kind: 'today'|'yesterday'|'last_7d'|'custom', date?: string}  $ctx
     */
    private function resolveDateContext(array $ctx): string
    {
        $kind = (string) ($ctx['kind'] ?? 'today');
        $today = now();

        return match ($kind) {
            'yesterday' => $today->copy()->subDay()->toDateString(),
            'last_7d' => $today->copy()->subDays(7)->toDateString().' s/d '.$today->toDateString(),
            'custom' => $this->safeCustomDate($ctx['date'] ?? null, $today->toDateString()),
            default => $today->toDateString(), // 'today' or unknown
        };
    }

    private function safeCustomDate(?string $candidate, string $fallback): string
    {
        if (! is_string($candidate) || $candidate === '') {
            return $fallback;
        }
        // Hanya terima format YYYY-MM-DD ketat — supaya prompt tidak
        // di-inject string aneh dari caller.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            return $fallback;
        }

        return $candidate;
    }

    /**
     * Build context block lengkap yang dipakai di setiap user prompt.
     *
     * @param  array<int, string>  $schemaLines
     */
    private function buildContextBlock(string $industry, string $resolvedDate, array $schemaLines): string
    {
        // Cap schema lines untuk safety prompt-size (kalau scan_results
        // mengandung ratusan tabel × puluhan kolom). 600 baris ~ aman.
        $cappedLines = array_slice($schemaLines, 0, 600);
        $schemaText = implode("\n", $cappedLines);
        if (count($schemaLines) > count($cappedLines)) {
            $schemaText .= "\n(... ".(count($schemaLines) - count($cappedLines)).' baris skema lain dipotong untuk efisiensi prompt)';
        }

        return "Industri/bidang client: ".$industry
            ."\nTanggal konteks: ".$resolvedDate
            ."\nSkema database (tabel.kolom : tipe):\n"
            .$schemaText;
    }

    /**
     * Extract SQL string dari response AiService::ask().
     *
     * `ask()` mencoba decode JSON; kalau gagal → key 'raw' = full content.
     * Untuk service ini kita prefer raw string SQL daripada JSON, jadi
     * cek beberapa shape yang mungkin.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractSqlFromAiResponse(array $response): ?string
    {
        // Shape 1: ask() menambahkan 'raw' kalau bukan JSON valid.
        if (isset($response['raw']) && is_string($response['raw'])) {
            return $response['raw'];
        }

        // Shape 2: AI patuh dan return JSON {"sql": "..."}.
        if (isset($response['sql']) && is_string($response['sql'])) {
            return $response['sql'];
        }

        // Shape 3: AI return JSON {"generated_sql": "..."}.
        if (isset($response['generated_sql']) && is_string($response['generated_sql'])) {
            return $response['generated_sql'];
        }

        // Shape 4: AI return JSON {"sql_queries": ["..."]} (kompatibel dgn
        // pattern di AiService::generateSqlFromText).
        if (isset($response['sql_queries']) && is_array($response['sql_queries'])) {
            $first = $response['sql_queries'][0] ?? null;
            if (is_string($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * Bersihkan string SQL dari markdown fence / quote / whitespace ekstra.
     */
    private function cleanSqlString(string $raw): string
    {
        $sql = trim($raw);

        // Hapus markdown code fence: ```sql ... ``` atau ``` ... ```
        $sql = preg_replace('/^```(?:sql|SQL)?\s*\n?/', '', $sql) ?? $sql;
        $sql = preg_replace('/\n?```\s*$/', '', $sql) ?? $sql;

        // Hapus prefix "SQL:" atau "Query:" yang kadang AI tambahkan.
        $sql = preg_replace('/^(SQL|Query|QUERY)\s*:\s*/i', '', $sql) ?? $sql;

        return trim($sql);
    }

    /**
     * Strip SQL comments: line `-- ...` dan block `/* ... *\/`.
     */
    private function stripSqlComments(string $sql): string
    {
        // Block comments /* ... */ (multi-line aware).
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        // Line comments -- ... sampai akhir baris.
        $sql = preg_replace('/--[^\n\r]*/', ' ', $sql) ?? $sql;
        // Hash comments # ... (MySQL).
        $sql = preg_replace('/#[^\n\r]*/', ' ', $sql) ?? $sql;

        return $sql;
    }

    /**
     * Build error result untuk semua cases sekaligus (early-fail).
     *
     * @param  array<int, array{id: string, query_text: string}>  $cases
     * @return array<int, array{case_id: string, query_text: string, generated_sql: string|null, error: string|null}>
     */
    private function shortCircuitAll(array $cases, string $errorMessage): array
    {
        $out = [];
        foreach ($cases as $case) {
            $caseId = (string) ($case['id'] ?? '(missing-id)');
            $queryText = (string) ($case['query_text'] ?? '');
            $out[] = CaseResult::error($caseId, $queryText, $errorMessage)->toArray();
        }

        return $out;
    }
}
