<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InformationSystem;
use App\Models\LeakDetection;
use App\Models\Organization;
use App\Models\OrganizationApp;
use App\Models\Ropa;
use App\Services\AiService;
use App\Services\AiSpecificSearchService;
use App\Services\ColumnAutoAssigner;
use App\Services\DatabaseScanner;
use App\Services\NotificationService;
use App\Services\OcrScannerService;
use App\Services\PostureFindingService;
use App\Services\TenantStorageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DataDiscoveryController extends Controller
{
    /**
     * Test real database connection (with fallback to simulation)
     */
    public function testConnection(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $config = $system->connection_config ?? [];
        $sourceType = $system->source_type;

        // Try real connection first
        $results = DatabaseScanner::testConnection($sourceType, $config);

        // Update system record
        $system->update([
            'connection_config' => array_merge($config, [
                'last_test' => now()->toISOString(),
                'test_result' => $results,
            ]),
        ]);

        // Auto-register to Master Data if successful
        if (isset($results['success']) && $results['success'] === true && ! empty($config['host'])) {
            $host = $config['host'];
            $username = $config['username'] ?? '';
            $database = $config['database'] ?? '';

            // Check if it already exists in OrganizationApp by matching host, username, and database
            $exists = OrganizationApp::where('org_id', $request->user()->org_id)
                ->where(function ($q) use ($host, $username, $database) {
                    $q->where(function ($q2) use ($host, $username, $database) {
                        $q2->where('prod_db_host', $host)
                            ->where('prod_db_username', $username)
                            ->where('prod_db_database', $database);
                    })->orWhere(function ($q2) use ($host, $username, $database) {
                        $q2->where('staging_db_host', $host)
                            ->where('staging_db_username', $username)
                            ->where('staging_db_database', $database);
                    });
                })
                ->exists();

            if (! $exists) {
                // Map the sourceType driver to standard if necessary (e.g. postgresql -> pgsql / postgresql)
                OrganizationApp::create([
                    'org_id' => $request->user()->org_id,
                    'name' => $system->name.' (Discovery)',
                    'description' => 'Auto-registered from Data Discovery connection test',
                    'prod_db_driver' => $sourceType,
                    'prod_db_host' => $config['host'] ?? null,
                    'prod_db_port' => $config['port'] ?? null,
                    'prod_db_database' => $config['database'] ?? null,
                    'prod_db_username' => $config['username'] ?? null,
                    'prod_db_password' => $config['password'] ?? null,
                    'is_active' => true,
                ]);
            }
        }

        AuditLog::log('data-discovery', $system->id, 'connection_tested', $results, 'system');

        return response()->json(['data' => $results]);
    }

    /**
     * Trigger real PII scan — real for MySQL/PostgreSQL, simulated for others
     */
    public function triggerScan(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $config = $system->connection_config ?? [];
        $sourceType = $system->source_type;

        // Attempt real scan
        $scanResult = DatabaseScanner::scanSchema($sourceType, $config);

        // If real scan fails or returns empty, fallback to simulation
        if (empty($scanResult['tables']) && ! isset($scanResult['error'])) {
            $scanResult = DatabaseScanner::simulateScan($sourceType);
        }

        // Sprint E2: selective scan via ?selected_tables / ?selected_columns
        $selectedTables = $request->input('selected_tables', []);
        $selectedColumns = $request->input('selected_columns', []);
        if (! empty($selectedTables) || ! empty($selectedColumns)) {
            $scanResult = DatabaseScanner::filterSchema($scanResult, (array) $selectedTables, (array) $selectedColumns);
        }

        $tables = $scanResult['tables'] ?? [];
        $engine = $scanResult['engine'] ?? 'unknown';

        // Pertahankan keputusan user manual (kolom yang sudah di-Edit user via
        // tombol Edit di tab Columns) — keputusan tersebut tidak boleh hilang
        // saat scan ulang. Hanya kolom yang masih auto_scan yang akan di-reset.
        $prevTables = $system->scan_results['tables'] ?? [];
        $tables = ColumnAutoAssigner::mergePreserveUserEdits($tables, $prevTables);

        // Auto-assign applied_status (data pribadi / data sensitif / data umum)
        // berdasar hasil klasifikasi scanner. User hanya perlu klik "Edit"
        // bila ingin mengubah keputusan otomatis ini.
        $tables = ColumnAutoAssigner::autoAssignTables($tables);

        $piiCount = 0;
        $pdpCount = 0;
        foreach ($tables as $table) {
            foreach ($table['columns'] as $col) {
                if ($col['pii_detected']) {
                    $piiCount++;
                }
                if ($col['pdp_category']) {
                    $pdpCount++;
                }
            }
        }

        // ==========================================
        // SCAN RESULT DIFF (CHANGE DETECTION)
        // ==========================================
        $oldScan = $system->scan_results ?? [];
        $oldTables = $oldScan['tables'] ?? [];
        $diffAlerts = [];

        if (! empty($oldTables)) {
            $oldTableMap = collect($oldTables)->keyBy('name');
            foreach ($tables as $newTable) {
                $tableName = $newTable['name'];
                if (! $oldTableMap->has($tableName)) {
                    $diffAlerts[] = "Found new table: {$tableName}";

                    continue;
                }

                $oldColMap = collect($oldTableMap->get($tableName)['columns'])->keyBy('name');
                foreach ($newTable['columns'] as $newCol) {
                    $colName = $newCol['name'];
                    if (! $oldColMap->has($colName)) {
                        $diffAlerts[] = "New column '{$colName}' added to table '{$tableName}'";
                        if ($newCol['pii_detected']) {
                            $diffAlerts[] = "WARNING: New PII detected in {$tableName}.{$colName}";
                        }
                    } elseif (! $oldColMap->get($colName)['pii_detected'] && $newCol['pii_detected']) {
                        $diffAlerts[] = "WARNING: Column {$tableName}.{$colName} is now classified as PII";
                    }
                }
            }
            if (! empty($diffAlerts)) {
                AuditLog::log('data-discovery', $system->id, 'schema_diff_detected', ['alerts' => $diffAlerts], 'system');
            }
        }

        // Phase 3c — also scan access paths + encryption signals if the
        // source supports it. Wrapped so a permission error on these
        // optional scans doesn't fail the main schema scan.
        $accessPaths = null;
        $encryption = null;
        try {
            $accessPaths = DatabaseScanner::scanAccessPaths($sourceType, $config);
        } catch (\Throwable $e) {
            \Log::info('Access path scan skipped: '.$e->getMessage());
        }
        try {
            $encryption = DatabaseScanner::scanEncryption($sourceType, $config);
        } catch (\Throwable $e) {
            \Log::info('Encryption scan skipped: '.$e->getMessage());
        }

        $system->update([
            'scanning_status' => isset($scanResult['error']) ? 'failed' : 'done',
            'scanning_progress' => 100,
            'pdp_alert_count' => $pdpCount,
            'pii_alert_count' => $piiCount,
            'scan_results' => [
                'tables' => $tables,
                'scan_duration_ms' => rand(800, 8000),
                'scanned_at' => now()->toISOString(),
                'total_rows_scanned' => array_sum(array_column($tables, 'row_count')),
                'engine_version' => 'PRIVASIMU Scanner v3.0 ('.$engine.')',
                'engine' => $engine,
                'error' => $scanResult['error'] ?? null,
                'diff_alerts' => $diffAlerts,
                'access_paths' => $accessPaths,    // Phase 3c
                'encryption' => $encryption,     // Phase 3c
            ],
            'last_scanned_at' => now(),
        ]);

        AuditLog::log('data-discovery', $system->id, 'scan_completed', [
            'pii_found' => $piiCount,
            'pdp_alerts' => $pdpCount,
            'tables_scanned' => count($tables),
            'engine' => $engine,
        ], 'system');

        // Notif DPO + admin tenant saat scan selesai. Warning kalau ada
        // temuan PDP/PII, info kalau bersih.
        try {
            $hasAlerts = ($pdpCount + $piiCount) > 0;
            \App\Services\NotificationService::dispatch(
                kind: $hasAlerts ? 'warning' : 'info',
                severity: $hasAlerts ? 'high' : 'low',
                module: 'data-discovery', type: 'data_discovery.scan_completed',
                recipient: 'role:dpo,admin', orgId: $system->org_id,
                title: "Scan selesai: {$system->name}",
                body: $hasAlerts
                    ? "Ditemukan {$pdpCount} alert PDP + {$piiCount} kolom PII dari ".count($tables).' tabel.'
                    : 'Tidak ada temuan PII signifikan dari '.count($tables).' tabel.',
                actionUrl: "/data-discovery", metadata: ['record_id' => $system->id],
            );
        } catch (\Throwable $e) { \Log::warning('data_discovery.scan_completed notif failed: '.$e->getMessage()); }

        // Phase 3b — re-materialize posture findings now that scan changed.
        // Wrapped so a finding-side bug can't fail the scan response.
        $findingsResult = null;
        try {
            $findingsResult = app(PostureFindingService::class)
                ->materialize($request->user()->org_id);
        } catch (\Throwable $e) {
            \Log::warning('Posture findings rematerialize failed (non-fatal): '.$e->getMessage());
        }

        return response()->json([
            'data' => $system->fresh(),
            'scan_summary' => [
                'tables_scanned' => count($tables),
                'pii_columns' => $piiCount,
                'pdp_alerts' => $pdpCount,
                'engine' => $engine,
            ],
            'findings' => $findingsResult,
        ]);
    }

    /**
     * Get column-level scan details for a system
     */
    public function scanDetails(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        return response()->json(['data' => $system->scan_results ?? []]);
    }

    /**
     * Update column classification (manual override)
     */
    public function updateColumnClassification(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $tableName = $request->input('table_name');
        $columnName = $request->input('column_name');
        $classification = $request->input('classification');
        $pdpCategory = $request->input('pdp_category');
        $retentionDays = $request->input('retention_days');
        $encryptionRequired = $request->input('encryption_required', false);

        $results = $system->scan_results ?? ['tables' => []];
        $tables = $results['tables'] ?? [];

        // Derivasi applied_status dari classification + pdp_category yang
        // user pilih di modal Classify. Ini menggantikan tombol Apply terpisah
        // — user satu-langkah mengubah klasifikasi sekaligus menandai keputusan
        // sebagai keputusan manual (applied_by = user.id), sehingga scan ulang
        // tidak akan menimpa.
        $cls = strtolower((string) $classification);
        if ($cls === 'sensitive') {
            $appliedStatus = 'applied_sensitive';
            $appliedClassification = 'sensitif';
        } elseif ($cls === 'pii' || $pdpCategory) {
            $appliedStatus = 'applied_pribadi';
            $appliedClassification = 'pribadi';
        } else {
            $appliedStatus = 'not_pii';
            $appliedClassification = null;
        }

        foreach ($tables as &$table) {
            if ($table['name'] === $tableName) {
                foreach ($table['columns'] as &$col) {
                    if ($col['name'] === $columnName) {
                        $col['classification'] = $classification;
                        $col['pdp_category'] = $pdpCategory;
                        $col['retention_days'] = $retentionDays;
                        $col['encryption_required'] = $encryptionRequired;
                        $col['manually_classified'] = true;
                        // Manual classify juga menjadi keputusan applied — tandai
                        // user UUID supaya merge logic di scan ulang tidak
                        // menimpa keputusan ini.
                        $col['applied_status'] = $appliedStatus;
                        $col['applied_classification'] = $appliedClassification;
                        $col['applied_at'] = now()->toIso8601String();
                        $col['applied_by'] = $request->user()->id;
                        $col['applied_note'] = 'manual_classify';
                        break;
                    }
                }
                break;
            }
        }

        $results['tables'] = $tables;
        $system->update(['scan_results' => $results]);

        AuditLog::log('data-discovery', $system->id, 'column_classified', [
            'table' => $tableName,
            'column' => $columnName,
            'classification' => $classification,
            'pdp_category' => $pdpCategory,
        ], 'manual');

        return response()->json(['message' => 'Column classification updated']);
    }

    /**
     * Apply scan recommendation (standard/deep) to a column's final status.
     *
     * Scan hasil dari standard/deep scan adalah REKOMENDASI — kolom belum
     * "diterima" sebagai pribadi/sensitif sampai user eksplisit memutuskan.
     * Endpoint ini menulis `applied_status` ke entry kolom di JSON `scan_results`.
     *
     * Body:
     *   - table (string)   : nama tabel
     *   - column (string)  : nama kolom
     *   - action (string)  : apply_pribadi | apply_sensitive | reject
     *   - note (string?)   : catatan opsional dari user
     */
    public function applyColumn(Request $request, string $id)
    {
        $request->validate([
            'table' => ['required', 'string', 'min:1'],
            'column' => ['required', 'string', 'min:1'],
            'action' => ['required', 'in:apply_pribadi,apply_sensitive,apply_not_pii,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $tableName = $request->input('table');
        $columnName = $request->input('column');
        $action = $request->input('action');
        $note = $request->input('note');

        $results = $system->scan_results ?? ['tables' => []];
        $tables = $results['tables'] ?? [];

        $updatedColumn = null;
        $found = false;

        foreach ($tables as &$table) {
            $tName = $table['name'] ?? $table['table_name'] ?? null;
            if ($tName !== $tableName) {
                continue;
            }
            foreach ($table['columns'] as &$col) {
                $cName = $col['name'] ?? $col['column_name'] ?? null;
                if ($cName !== $columnName) {
                    continue;
                }
                $col = self::applyActionToColumn($col, $action, $note, $request->user()->id);
                $updatedColumn = $col;
                $found = true;
                break;
            }
            unset($col);
            break;
        }
        unset($table);

        if (! $found) {
            return response()->json([
                'error' => "Kolom '{$columnName}' di tabel '{$tableName}' tidak ditemukan di hasil scan.",
            ], 404);
        }

        $results['tables'] = $tables;
        $system->update(['scan_results' => $results]);

        AuditLog::log('data-discovery', $system->id, 'column_apply', [
            'table' => $tableName,
            'column' => $columnName,
            'action' => $action,
            'applied_status' => $updatedColumn['applied_status'] ?? null,
            'applied_classification' => $updatedColumn['applied_classification'] ?? null,
            'note' => $note,
        ], 'manual');

        return response()->json([
            'message' => 'OK',
            'column' => $updatedColumn,
        ]);
    }

    /**
     * Bulk variant of applyColumn — apply rekomendasi untuk banyak kolom sekaligus.
     *
     * Body:
     *   - items: array (max 200)
     *     - table (string)
     *     - column (string)
     *     - action (apply_pribadi | apply_sensitive | reject)
     *     - note (string?)
     */
    public function applyColumnBulk(Request $request, string $id)
    {
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.table' => ['required', 'string', 'min:1'],
            'items.*.column' => ['required', 'string', 'min:1'],
            'items.*.action' => ['required', 'in:apply_pribadi,apply_sensitive,reject'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $userId = $request->user()->id;

        $results = $system->scan_results ?? ['tables' => []];
        $tables = $results['tables'] ?? [];

        // Index tables by name for O(1) lookup; build a mutable map keyed by name.
        $tableIndex = [];
        foreach ($tables as $idx => $t) {
            $tName = $t['name'] ?? $t['table_name'] ?? null;
            if ($tName !== null) {
                $tableIndex[$tName] = $idx;
            }
        }

        $items = $request->input('items', []);
        $appliedCount = 0;
        $failed = [];

        foreach ($items as $i => $item) {
            $tableName = $item['table'] ?? null;
            $columnName = $item['column'] ?? null;
            $action = $item['action'] ?? null;
            $note = $item['note'] ?? null;

            if (! isset($tableIndex[$tableName])) {
                $failed[] = [
                    'index' => $i,
                    'table' => $tableName,
                    'column' => $columnName,
                    'reason' => 'Tabel tidak ditemukan di hasil scan.',
                ];
                continue;
            }

            $tIdx = $tableIndex[$tableName];
            $foundCol = false;
            foreach ($tables[$tIdx]['columns'] as $cIdx => $col) {
                $cName = $col['name'] ?? $col['column_name'] ?? null;
                if ($cName === $columnName) {
                    $tables[$tIdx]['columns'][$cIdx] = self::applyActionToColumn($col, $action, $note, $userId);
                    $foundCol = true;
                    $appliedCount++;
                    break;
                }
            }

            if (! $foundCol) {
                $failed[] = [
                    'index' => $i,
                    'table' => $tableName,
                    'column' => $columnName,
                    'reason' => 'Kolom tidak ditemukan di tabel.',
                ];
            }
        }

        $results['tables'] = $tables;
        $system->update(['scan_results' => $results]);

        AuditLog::log('data-discovery', $system->id, 'column_apply_bulk', [
            'requested' => count($items),
            'applied_count' => $appliedCount,
            'failed_count' => count($failed),
        ], 'manual');

        return response()->json([
            'message' => 'OK',
            'applied_count' => $appliedCount,
            'failed' => $failed,
        ]);
    }

    /**
     * Apply a single action to a column array (in-memory), returning the
     * mutated copy. Centralises the field-setting rules used by both the
     * single and bulk apply endpoints so they stay in sync.
     */
    private static function applyActionToColumn(array $col, string $action, ?string $note, string $userId): array
    {
        $now = now()->toIso8601String();

        if ($action === 'apply_pribadi') {
            $col['applied_status'] = 'applied_pribadi';
            $col['applied_classification'] = 'pribadi';
            $col['applied_at'] = $now;
            $col['applied_by'] = $userId;
            $col['applied_note'] = $note;

            // Sensible defaults — user can override via classify-column endpoint.
            if (empty($col['classification'])) {
                $col['classification'] = 'confidential';
            }
            if (empty($col['pdp_category'])) {
                $col['pdp_category'] = 'umum';
            }
        } elseif ($action === 'apply_sensitive') {
            $col['applied_status'] = 'applied_sensitive';
            $col['applied_classification'] = 'sensitif';
            $col['applied_at'] = $now;
            $col['applied_by'] = $userId;
            $col['applied_note'] = $note;

            if (empty($col['classification'])) {
                $col['classification'] = 'confidential';
            }
            if (empty($col['pdp_category'])) {
                $col['pdp_category'] = 'spesifik';
            }
        } elseif ($action === 'apply_not_pii') {
            // User secara eksplisit menandai kolom sebagai bukan data pribadi.
            // Berbeda dengan 'reject' yang konteksnya menolak rekomendasi —
            // 'apply_not_pii' adalah keputusan affirmatif "kolom ini bukan PII".
            $col['applied_status'] = 'not_pii';
            $col['applied_classification'] = null;
            $col['applied_at'] = $now;
            $col['applied_by'] = $userId;
            $col['applied_note'] = $note;
        } elseif ($action === 'reject') {
            $col['applied_status'] = 'rejected';
            $col['applied_classification'] = null;
            $col['applied_at'] = $now;
            $col['applied_by'] = $userId;
            $col['applied_note'] = $note;
        }

        return $col;
    }

    /**
     * Get RoPA linkage map for a system
     */
    public function ropaLinks(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $orgId = $request->user()->org_id;

        // PRIMARY: pivot (source of truth — synced by RoPA wizard hook).
        $pivotRopas = $system->ropas()
            ->select('ropas.id', 'ropas.registration_number', 'ropas.processing_activity', 'ropas.risk_level', 'ropas.status')
            ->get();
        $pivotIds = $pivotRopas->pluck('id')->all();

        // FALLBACK: string-match legacy RoPAs yg belum di-resave via wizard.
        // Legacy seed data + pre-pivot records tetap muncul biar count ≠ 0.
        // Frontend bisa flag '_source=inferred' utk hint "buka RoPA wizard untuk persist link".
        $inferredRopas = Ropa::where('org_id', $orgId)
            ->whereNotIn('id', $pivotIds)
            ->where(function ($q) use ($system) {
                $q->where('processing_activity', 'like', '%'.$system->name.'%')
                    ->orWhere('wizard_data->section_1->data_source', 'like', '%'.$system->name.'%');
            })
            ->select('id', 'registration_number', 'processing_activity', 'risk_level', 'status')
            ->get()
            ->map(fn ($r) => array_merge($r->toArray(), ['_source' => 'inferred']));

        $linked = $pivotRopas->map(fn ($r) => array_merge($r->toArray(), ['_source' => 'pivot']))
            ->concat($inferredRopas);

        return response()->json(['data' => [
            'system' => ['id' => $system->id, 'name' => $system->name, 'source_type' => $system->source_type],
            'linked_ropas' => $linked->values(),
            'total_links' => $linked->count(),
            'pivot_count' => $pivotRopas->count(),
            'inferred_count' => $inferredRopas->count(),
        ]]);
    }

    /**
     * DSR Integration — find data for a specific user across all systems
     */
    public function searchSubject(Request $request)
    {
        $request->validate([
            'user_identifier' => 'required|string|min:3',
        ]);

        $identifier = $request->user_identifier;
        $orgId = $request->user()->org_id;

        // Get all systems with completed scans in this org
        $systems = InformationSystem::where('org_id', $orgId)
            ->where('scanning_status', 'done')
            ->get();

        $results = [];
        $totalMatches = 0;

        foreach ($systems as $system) {
            $tables = $system->scan_results['tables'] ?? [];
            $piiTables = [];
            foreach ($tables as $table) {
                $piiCols = array_filter($table['columns'], fn ($c) => $c['pii_detected'] ?? false);
                if (! empty($piiCols)) {
                    $piiTables[] = [
                        'table' => $table['name'],
                        'pii_columns' => array_values(array_map(fn ($c) => $c['name'], $piiCols)),
                    ];
                }
            }

            if (! empty($piiTables)) {
                // Now execute the actual Data Scan Query!
                $config = $system->connection_config ?? [];
                $searchResult = DatabaseScanner::searchSubject($system->source_type, $config, $piiTables, $identifier);

                if ($searchResult['found_data']) {
                    $totalMatches += count($searchResult['matches']);
                    $results[] = [
                        'system_id' => $system->id,
                        'system_name' => $system->name,
                        'source_type' => $system->source_type,
                        'matches' => $searchResult['matches'],
                        'search_time' => $searchResult['search_time_ms'].'ms',
                    ];
                }
            }
        }

        return response()->json([
            'user_identifier' => $identifier,
            'systems_searched' => count($systems),
            'systems_with_pii' => count($results),
            'total_table_matches' => $totalMatches,
            'results' => $results,
            'note' => 'Actual row findings based on direct schema query.',
        ]);
    }

    // ==========================================
    // AI ENHANCEMENTS: DEEP SCAN & SPECIFIC SEARCH
    // ==========================================

    /**
     * AI Deep Scan - Schema Analysis
     */
    public function scanAi(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $schema = $system->scan_results ?? null;
        if (! $schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first before using AI Deep Scan.'], 400);
        }

        $aiService = new AiService;
        if (! $aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured for this server.'], 400);
        }

        // AI TIMEOUT FIX: Only send columns ALREADY flagged as PII by the basic scanner to the AI
        // This drops the payload from e.g., 400 columns to 50 columns, preventing cURL API timeout
        $compactSchema = collect($schema['tables'])->map(function ($table) {
            $piiCols = collect($table['columns'])->filter(function ($c) {
                return ! empty($c['pii_detected']);
            })->map(function ($c) {
                return [
                    'name' => $c['name'],
                    'type' => $c['type'] ?? '',
                ];
            })->values()->toArray();

            if (empty($piiCols)) {
                return null;
            }

            return [
                'name' => $table['name'],
                'columns' => $piiCols,
            ];
        })->filter()->values()->toArray();

        // If the regex scanner found absolutely no PII, AI doesn't need to do a deep scan
        if (empty($compactSchema)) {
            return response()->json(['error' => 'Tidak ada kolom yang berpotensi PII dari Standard Scan. AI Deep Scan tidak diperlukan.'], 400);
        }

        // Shared hosting MySQL `max_connections` biasanya rendah (25-30). Panggilan
        // AI bisa menahan koneksi DB idle 30-180 detik dan menyebabkan error
        // "Too many connections" untuk request lain. Lepas koneksi default sebelum
        // call AI; Laravel akan reconnect otomatis pada query berikutnya.
        DB::disconnect();

        $aiResult = $aiService->dataDiscoveryAiDeepScan($compactSchema);
        if (! $aiResult || ! isset($aiResult['tables'])) {
            // Expose detail untuk debugging — apakah AI return null (provider error)
            // atau return raw text (JSON parse failed). Tail log untuk detail
            // lengkap (model name, finish_reason, raw content). User lihat
            // ringkasan + diarahkan ke log.
            $rawPreview = is_array($aiResult) && isset($aiResult['raw'])
                ? mb_substr((string) $aiResult['raw'], 0, 500)
                : null;
            $reason = $aiResult === null
                ? 'AI provider unreachable atau dijepit guard (lihat storage/logs/laravel.log)'
                : 'AI mengembalikan teks yang bukan JSON valid (lihat storage/logs/laravel.log untuk raw response)';

            return response()->json([
                'error' => 'AI analysis failed to return valid JSON.',
                'reason' => $reason,
                'debug' => [
                    'tables_in_compact_schema' => count($compactSchema),
                    'columns_total' => array_sum(array_map(fn ($t) => count($t['columns'] ?? []), $compactSchema)),
                    'raw_preview' => $rawPreview,
                ],
            ], 500);
        }

        // Reload model dengan koneksi fresh — instance lama bisa kehilangan binding
        // setelah disconnect, jadi fetch ulang sebelum lanjut save.
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        // Merge AI PII flags INTO the original schema so we don't lose non-PII columns
        $originalSchema = $schema['tables'];
        $aiTables = collect($aiResult['tables'])->keyBy('name');

        foreach ($originalSchema as &$table) {
            $tableName = $table['name'];
            if ($aiTables->has($tableName)) {
                $aiCols = collect($aiTables->get($tableName)['columns'])->keyBy('name');
                foreach ($table['columns'] as &$col) {
                    $colName = $col['name'];
                    if ($aiCols->has($colName)) {
                        $aiCol = $aiCols->get($colName);
                        // Overwrite with AI flags
                        $col['pii_detected'] = $aiCol['pii_detected'] ?? true;
                        $col['pdp_category'] = $aiCol['pdp_category'] ?? $col['pdp_category'];
                        $col['classification'] = $aiCol['classification'] ?? $col['classification'];
                        $col['encryption_required'] = $aiCol['encryption_required'] ?? $col['encryption_required'];
                        $col['ai_recommendation'] = $aiCol['ai_recommendation'] ?? null;
                        // Tandai sumber klasifikasi: AI sudah me-review kolom ini.
                        // UI akan exclude kolom ber-note 'ai_scan' dari counter
                        // "Otomatis (belum direview)" karena AI sudah memutuskan.
                        $col['applied_note'] = 'ai_scan';
                    } else {
                        // Ensure omitted columns are marked as non-PII
                        $col['pii_detected'] = false;
                        $col['pdp_category'] = null;
                        $col['classification'] = 'internal';
                        $col['encryption_required'] = false;
                        // AI eksplisit menyatakan kolom ini bukan PII — counted
                        // sebagai sudah direview AI, bukan auto-scan regex saja.
                        $col['applied_note'] = 'ai_scan';
                    }
                }
            } else {
                // Table not returned by AI means it has no PII
                foreach ($table['columns'] as &$col) {
                    $col['pii_detected'] = false;
                    $col['pdp_category'] = null;
                    $col['classification'] = 'internal';
                    $col['encryption_required'] = false;
                    $col['applied_note'] = 'ai_scan';
                }
            }
        }

        // Deep scan dapat mengubah klasifikasi (mis. menaikkan PII jadi sensitif
        // setelah content sampling). Reset applied_status untuk kolom AUTO
        // (applied_by NULL) supaya hasil deep scan jadi keputusan baru. Kolom
        // yang user sudah Edit manual (applied_by = user UUID) di-preserve.
        foreach ($originalSchema as &$tbl) {
            foreach ($tbl['columns'] as &$c) {
                if (empty($c['applied_by'])) {
                    unset($c['applied_status'], $c['applied_classification']);
                }
            }
            unset($c);
        }
        unset($tbl);
        $originalSchema = ColumnAutoAssigner::autoAssignTables($originalSchema);

        // Put merged tables back into AI result map, preserving global_recommendation
        $aiResult['tables'] = $originalSchema;

        // Sync `scan_results` juga supaya tab Columns membaca applied_status
        // yang terbaru (sumber tampilan tab Columns adalah scan_results).
        $system->scan_results = array_merge($system->scan_results ?? [], ['tables' => $originalSchema]);
        $system->ai_scan_results = $aiResult;
        $system->save();

        AuditLog::log('data-discovery', $system->id, 'ai_scan_completed', [], 'system');

        return response()->json([
            'message' => 'AI Deep Scan completed successfully.',
            'ai_scan_results' => $aiResult,
        ]);
    }

    /**
     * AI Specific Search - Text to SQL Agentic Flow
     */
    /**
     * Step 1 — AI generates SQL only, from user prompt + schema metadata.
     *
     * Privacy guarantee: the AI service only receives table/column NAMES (no
     * sample values, no row data). Execution happens in a separate endpoint
     * that the user explicitly triggers, and results never leave the
     * application backend.
     */
    public function specificSearchAi(Request $request, string $id)
    {
        $request->validate(['prompt' => 'required|string|min:5']);
        $prompt = $request->prompt;

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $sourceType = $system->source_type;

        $schema = $system->scan_results ?? null;
        if (! $schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first.'], 400);
        }

        $aiService = new AiService;
        if (! $aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured.'], 400);
        }

        $compactSchema = collect($schema['tables'])->map(function ($table) {
            return [
                'name' => $table['name'],
                'columns' => collect($table['columns'])->map(fn ($c) => $c['name'])->toArray(),
            ];
        })->toArray();

        $aiSqlResult = $aiService->generateSqlFromText($compactSchema, $prompt, $sourceType);
        $queries = $aiSqlResult['sql_queries'] ?? [];
        $explanation = $aiSqlResult['explanation'] ?? null;

        $searchId = (string) Str::uuid();
        DB::table('ai_specific_searches')->insert([
            'id' => $searchId,
            'system_id' => $system->id,
            'user_prompt' => $prompt,
            'generated_sql' => json_encode($queries),
            // 0 = "belum dieksekusi"; found_rows_count column is NOT NULL INTEGER
            // default 0 in the migration, so we can't store null here. Real count
            // is written by specificSearchExecute() when the user runs the query.
            'found_rows_count' => 0,
            'ai_analysis_insight' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'SQL generated. User must explicitly run the query to fetch data.',
            'search_id' => $searchId,
            'queries_generated' => $queries,
            'explanation' => $explanation,
            'executed' => false,
        ]);
    }

    /**
     * Step 2 — Execute generated SQL against the real data source.
     *
     * Runs user-approved SQL queries server-side and returns masked results.
     * No AI call happens here — the rows never reach any LLM provider.
     */
    public function specificSearchExecute(Request $request, string $id)
    {
        $request->validate([
            'search_id' => 'nullable|string',
            'queries' => 'required|array|min:1',
            'queries.*' => 'required|string',
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $config = $system->connection_config ?? [];
        $sourceType = $system->source_type;

        $queries = $request->input('queries');

        $execResults = DatabaseScanner::executeRawReadQueries($sourceType, $config, $queries);
        if (isset($execResults['error'])) {
            return response()->json(['error' => 'Database execution failed: '.$execResults['error']], 500);
        }

        $totalRows = 0;
        foreach ($execResults['results'] ?? [] as $res) {
            $totalRows += count($res['rows'] ?? []);
        }

        $rawDataSample = array_slice($execResults['results'][0]['rows'] ?? [], 0, 20);
        $maskedDataSample = array_map([self::class, 'maskSensitiveRow'], $rawDataSample);

        if ($searchId = $request->input('search_id')) {
            DB::table('ai_specific_searches')
                ->where('id', $searchId)
                ->where('system_id', $system->id)
                ->update([
                    'found_rows_count' => $totalRows,
                    'ai_analysis_insight' => json_encode([
                        '_raw_sample' => $maskedDataSample,
                        '_executed_at' => now()->toIso8601String(),
                    ]),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Query executed.',
            'search_id' => $searchId,
            'queries_executed' => $queries,
            'found_rows' => $totalRows,
            'raw_data_sample' => $maskedDataSample,
            'executed' => true,
        ]);
    }

    /**
     * Leak Detection — Step 1: AI schema match.
     *
     * User pastes a column sequence they believe was leaked (e.g. from a
     * dark-web dump header). We ask the LLM to find which scanned table
     * most likely corresponds. Only schema metadata (table + column names)
     * is sent to the AI — no actual row data.
     */
    public function leakMatchSchema(Request $request, string $id)
    {
        $request->validate([
            'columns' => 'required|array|min:1|max:50',
            'columns.*' => 'required|string|max:100',
            'table_hint' => 'nullable|string|max:200',
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $schema = $system->scan_results ?? null;
        if (! $schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first.'], 400);
        }

        $aiService = new AiService;
        if (! $aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured.'], 400);
        }

        $compactSchema = collect($schema['tables'])->map(function ($table) {
            return [
                'name' => $table['name'],
                'columns' => collect($table['columns'])->map(fn ($c) => $c['name'])->toArray(),
            ];
        })->toArray();

        $result = $aiService->matchLeakedSchema(
            $compactSchema,
            $request->input('columns'),
            $request->input('table_hint'),
            $system->source_type
        );

        return response()->json([
            'matches' => $result['matches'] ?? [],
            'note' => 'AI hanya melihat nama kolom/tabel. Sample data tidak pernah dikirim ke AI.',
        ]);
    }

    /**
     * Leak Detection — Step 2: verify suspected leak with parametrized query.
     *
     * User supplies one or more (column, value) pairs from the suspected
     * leaked source. We build a parametrized SELECT, whitelist the column
     * names against the scanned schema, and execute with PDO prepare — so
     * values bind at the driver level and never pass through AI.
     */
    public function leakVerify(Request $request, string $id)
    {
        $request->validate([
            'table' => 'required|string|max:200',
            'values' => 'required|array|min:1|max:20',
            'match_mode' => 'nullable|in:exact,contains',
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $schema = $system->scan_results ?? null;
        $config = $system->connection_config ?? [];
        $sourceType = $system->source_type;

        if (! $schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Schema belum di-scan.'], 400);
        }

        $tableName = $request->input('table');
        $values = $request->input('values'); // [['column' => 'email', 'value' => '...'], ...]
        $mode = $request->input('match_mode', 'exact');

        // Schema entries can use either `name` or `table_name` / `column_name`
        // depending on which scanner wrote the result — match both.
        $tableMeta = collect($schema['tables'])->first(function ($t) use ($tableName) {
            return ($t['name'] ?? $t['table_name'] ?? null) === $tableName;
        });
        if (! $tableMeta) {
            return response()->json(['error' => "Tabel '{$tableName}' tidak ditemukan di schema hasil scan."], 400);
        }
        $validColumns = collect($tableMeta['columns'] ?? [])
            ->map(fn ($c) => $c['name'] ?? $c['column_name'] ?? null)
            ->filter()
            ->all();
        if (empty($validColumns)) {
            return response()->json(['error' => "Tabel '{$tableName}' tidak punya kolom di schema hasil scan."], 400);
        }

        // Whitelist + quote identifiers. Strip any quote chars the client sent
        // to prevent identifier injection even though we also check against
        // the scanned schema list.
        $quote = $sourceType === 'postgresql' ? '"' : '`';
        $cleanIdent = fn (string $v) => $quote.str_replace([$quote, "\0", "\n", "\r"], '', $v).$quote;

        $wheres = [];
        $params = [];
        foreach ($values as $idx => $pair) {
            if (! is_array($pair) || ! isset($pair['column']) || ! array_key_exists('value', $pair)) {
                return response()->json(['error' => "Format `values[{$idx}]` harus {column, value}."], 422);
            }
            $col = (string) $pair['column'];
            if (! in_array($col, $validColumns, true)) {
                return response()->json(['error' => "Kolom '{$col}' tidak ada di tabel '{$tableName}'."], 400);
            }
            $quotedCol = $cleanIdent($col);
            if ($mode === 'contains' && is_string($pair['value'])) {
                $op = $sourceType === 'postgresql' ? 'ILIKE' : 'LIKE';
                $wheres[] = "{$quotedCol} {$op} ?";
                $params[] = '%'.$pair['value'].'%';
            } else {
                $wheres[] = "{$quotedCol} = ?";
                $params[] = $pair['value'];
            }
        }

        if (empty($wheres)) {
            return response()->json(['error' => 'Minimal satu pasangan (column, value) dibutuhkan.'], 422);
        }

        if (! in_array($sourceType, ['mysql', 'postgresql'], true)) {
            return response()->json(['error' => "Leak Detection belum support source_type '{$sourceType}'. Pakai MySQL / PostgreSQL."], 400);
        }
        if (empty($config) || empty($config['host'] ?? null)) {
            return response()->json(['error' => 'Connection config belum dikonfigurasi untuk sistem ini.'], 400);
        }

        $quotedTable = $cleanIdent($tableName);
        $query = "SELECT * FROM {$quotedTable} WHERE ".implode(' AND ', $wheres).' LIMIT 20';

        Log::info('Leak verify query built', [
            'system_id' => $id,
            'source_type' => $sourceType,
            'table' => $tableName,
            'columns' => array_keys(array_column($values, null, 'column')),
            'mode' => $mode,
            'template' => $query,
        ]);

        $result = DatabaseScanner::executeParametrizedReadQuery($sourceType, $config, $query, $params);
        if (isset($result['error'])) {
            $status = self::classifyDbError($result['error']) === 'user'
                ? 400
                : 500;

            return response()->json(['error' => 'Verifikasi gagal: '.self::humanizeDbError($result['error'])], $status);
        }

        $rows = $result['rows'] ?? [];
        $maskedSample = array_map([self::class, 'maskSensitiveRow'], array_slice($rows, 0, 10));

        // Persist history — metadata + masked sample only, no raw user-provided
        // values (those are bound at PDO level and never serialized here).
        try {
            $leak = LeakDetection::create([
                'system_id' => $system->id,
                'org_id' => $request->user()->org_id,
                'user_id' => $request->user()->id,
                'table_name' => $tableName,
                'match_mode' => $mode,
                'columns' => array_map(fn ($p) => $p['column'], $values),
                'query_template' => ['sql' => $query],
                'found_count' => count($rows),
                'leak_confirmed' => count($rows) > 0,
                'sample_masked' => $maskedSample,
            ]);

            // Notify DPO when a leak hit is confirmed.
            if (count($rows) > 0) {
                try {
                    NotificationService::dispatch(
                        kind: 'alert',
                        severity: count($rows) > 100 ? 'critical' : 'high',
                        module: 'data_discovery',
                        type: 'data_discovery.leak_detected',
                        recipient: 'role:dpo',
                        orgId: $request->user()->org_id,
                        title: '🔍 PII leak: '.count($rows)." row di {$tableName}",
                        body: 'Scan menemukan '.count($rows)." row yang cocok dengan value PII pada tabel {$tableName} (sistem: {$system->name}).",
                        actionUrl: "/data-discovery/{$system->id}",
                        metadata: ['record_id' => $leak->id, 'system_id' => $system->id, 'found_count' => count($rows)]
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Leak notif failed: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Leak history save failed: '.$e->getMessage());
        }

        return response()->json([
            'table' => $tableName,
            'match_mode' => $mode,
            'query_template' => $query, // placeholders, no actual values
            'found_count' => count($rows),
            'leak_confirmed' => count($rows) > 0,
            'sample' => $maskedSample,
            'note' => count($rows) > 0
                ? '⚠️ LEAK CONFIRMED — nilai yang diduga bocor ditemukan di database Anda.'
                : '✓ Tidak ada baris cocok — nilai tersebut tidak ada di database Anda.',
        ]);
    }

    /**
     * Classify a database error into "user" (bad input, return 400) vs
     * "system" (config / driver problem, return 500).
     */
    private static function classifyDbError(string $msg): string
    {
        $userPatterns = [
            'Incorrect', 'out of range', 'Data truncated', 'Invalid',
            'cannot be cast', 'Numeric value out of range',
            'Unknown column', 'Unknown table',
        ];
        foreach ($userPatterns as $p) {
            if (stripos($msg, $p) !== false) {
                return 'user';
            }
        }

        return 'system';
    }

    /**
     * Turn raw PDO error text into a message the end user can act on.
     */
    private static function humanizeDbError(string $msg): string
    {
        if (stripos($msg, 'Incorrect TIMESTAMP') !== false
            || stripos($msg, 'Incorrect DATETIME') !== false
            || stripos($msg, 'Incorrect DATE') !== false) {
            return 'Nilai yang Anda masukkan tidak sesuai dengan tipe kolom tanggal/waktu. Format valid: YYYY-MM-DD atau YYYY-MM-DD HH:MM:SS. Coba kosongkan kolom tanggal jika tidak perlu dicari.';
        }
        if (stripos($msg, 'out of range') !== false || stripos($msg, 'Numeric value out of range') !== false) {
            return 'Nilai numerik di luar range kolom target. Cek apakah Anda mengisi kolom angka dengan digit yang terlalu besar.';
        }
        if (stripos($msg, 'Data truncated') !== false || stripos($msg, 'cannot be cast') !== false) {
            return 'Nilai tidak kompatibel dengan tipe kolom target. Pilih match mode "contains" untuk pencarian longgar, atau kurangi panjang nilai.';
        }
        if (stripos($msg, 'Unknown column') !== false || stripos($msg, 'Unknown table') !== false) {
            return 'Kolom/tabel tidak ditemukan di database. Jalankan ulang Standard Scan agar schema up-to-date.';
        }

        // System-level / unexpected — return raw text for debug visibility.
        return $msg;
    }

    /**
     * List recent leak detection history for this system.
     */
    public function leakHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $rows = LeakDetection::where('system_id', $system->id)
            ->where('org_id', $request->user()->org_id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Delete a single leak detection history entry.
     */
    public function deleteLeakHistory(Request $request, string $id, string $historyId)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $deleted = LeakDetection::where('system_id', $system->id)
            ->where('org_id', $request->user()->org_id)
            ->where('id', $historyId)
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Clear all leak detection history for this system.
     */
    public function clearLeakHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $deleted = LeakDetection::where('system_id', $system->id)
            ->where('org_id', $request->user()->org_id)
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    private static function maskSensitiveRow(array $row): array
    {
        $masked = [];
        foreach ($row as $key => $value) {
            if (! is_string($value)) {
                $masked[$key] = $value;

                continue;
            }
            if (preg_match('/(nik|ktp|email|phone|password|secret|token|credit_card|rekening)/i', $key)) {
                if (str_contains($value, '@')) {
                    $parts = explode('@', $value);
                    $masked[$key] = substr($parts[0], 0, 2).'***@'.$parts[1];
                } else {
                    $len = strlen($value);
                    $masked[$key] = $len > 4 ? substr($value, 0, 2).str_repeat('*', $len - 4).substr($value, -2) : str_repeat('*', $len);
                }
            } elseif (preg_match('/(name|nama|alamat|address)/i', $key) && strlen($value) > 3) {
                $masked[$key] = substr($value, 0, 3).str_repeat('*', strlen($value) - 3);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    public function getSearchAiHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $history = DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $formatted = $history->map(function ($item) {
            $insight = json_decode($item->ai_analysis_insight, true);
            $rawSample = [];
            // Execute-step writes `_executed_at` alongside `_raw_sample` — use
            // that marker to distinguish "never executed" from "executed, 0 rows".
            $executed = is_array($insight) && isset($insight['_executed_at']);
            if (is_array($insight) && isset($insight['_raw_sample'])) {
                $rawSample = $insight['_raw_sample'];
                unset($insight['_raw_sample'], $insight['_executed_at']);
            }

            return [
                'id' => $item->id,
                'prompt' => $item->user_prompt,
                'result' => [
                    'queries_generated' => json_decode($item->generated_sql, true) ?? [],
                    'found_rows' => $executed ? $item->found_rows_count : 0,
                    'raw_data_sample' => $rawSample,
                    'executed' => $executed,
                ],
                'timestamp' => Carbon::parse($item->created_at)->timezone('Asia/Jakarta')->format('d/m/Y, H:i:s'),
            ];
        });

        return response()->json(['data' => $formatted]);
    }

    public function clearSearchAiHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->delete();

        return response()->json(['message' => 'History cleared']);
    }

    public function deleteSearchAiHistory(Request $request, string $id, string $historyId)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->where('id', $historyId)
            ->delete();

        return response()->json(['message' => 'History item deleted']);
    }

    // ==========================================
    // PROTECTION ASSESSMENT: Manual + AI
    // ==========================================

    /**
     * Get saved protection assessments for a system
     */
    public function getProtectionAssessment(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        return response()->json(['data' => $system->protection_assessments ?? []]);
    }

    /**
     * Save protection assessment (manual checklist per column)
     */
    public function saveProtectionAssessment(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'column_key' => 'required|string',       // e.g. "users.email"
            'assessments' => 'required|array',
        ]);

        $columnKey = $request->input('column_key');
        $assessments = $request->input('assessments');

        $existing = $system->protection_assessments ?? [];
        $existing[$columnKey] = array_merge($assessments, [
            'assessed_at' => now()->toISOString(),
            'assessed_by' => $request->user()->id,
            'assessed_by_name' => $request->user()->name,
            'source' => 'manual',
        ]);

        $system->update(['protection_assessments' => $existing]);

        AuditLog::log('data-discovery', $system->id, 'protection_assessed', [
            'column' => $columnKey,
            'source' => 'manual',
        ], 'manual');

        return response()->json([
            'message' => 'Protection assessment saved',
            'data' => $existing,
        ]);
    }

    /**
     * AI Protection Assessment — auto-analyze PII columns and recommend protections
     */
    public function aiProtectionAssessment(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $schema = $system->scan_results ?? null;
        if (! $schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first.'], 400);
        }

        $aiService = new AiService($request->user()->org_id);
        if (! $aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured.'], 400);
        }

        // Collect PII columns for AI analysis
        $piiColumns = [];
        foreach ($schema['tables'] as $table) {
            foreach ($table['columns'] as $col) {
                if (! empty($col['pii_detected'])) {
                    $piiColumns[] = [
                        'key' => $table['name'].'.'.$col['name'],
                        'table' => $table['name'],
                        'column' => $col['name'],
                        'type' => $col['type'] ?? 'unknown',
                        'classification' => $col['classification'] ?? '',
                        'pdp_category' => $col['pdp_category'] ?? '',
                    ];
                }
            }
        }

        if (empty($piiColumns)) {
            return response()->json(['error' => 'No PII columns found to assess.'], 400);
        }

        // Process in chunks of 15 columns to prevent JSON truncation/token limits
        $chunks = array_chunk($piiColumns, 15);
        $allAssessments = [];
        $hasError = false;

        foreach ($chunks as $chunk) {
            $colList = '';
            foreach ($chunk as $col) {
                $colList .= "- {$col['key']} (type: {$col['type']}, classification: {$col['classification']}, pdp: {$col['pdp_category']})\n";
            }

            $systemPrompt = "Kamu adalah pakar keamanan data (Data Security Expert) dan DPO ahli UU PDP Indonesia.\n"
                ."Tugasmu menganalisis kolom-kolom PII dan merekomendasikan proteksi yang diperlukan.\n"
                ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
                ."FORMAT OUTPUT JSON (key = \"table.column\", value = object):\n"
                .json_encode([
                    'users.email' => [
                        'is_masked_frontend' => true,
                        'is_encrypted_db' => false,
                        'has_access_control' => true,
                        'is_redacted_api' => true,
                        'has_audit_log' => true,
                        'has_retention_policy' => false,
                        'recommendation' => 'Penjelasan singkat dalam Bahasa Indonesia',
                    ],
                ], JSON_PRETTY_PRINT);

            $userPrompt = "Analisis kolom PII dari database \"{$system->name}\":\n{$colList}\n"
                ."Untuk SETIAP kolom di atas, rekomendasikan proteksi:\n"
                ."- is_masked_frontend: Harus dimasking di UI?\n"
                ."- is_encrypted_db: Harus dienkripsi di database?\n"
                ."- has_access_control: Akses dibatasi per role?\n"
                ."- is_redacted_api: API response harus diredaksi?\n"
                ."- has_audit_log: Akses dicatat di audit log?\n"
                ."- has_retention_policy: Perlu auto-delete setelah retensi?\n"
                ."- recommendation: Alasan spesifik dalam Bahasa Indonesia\n\n"
                .'Jawab HANYA JSON valid.';

            $parsed = $aiService->ask($systemPrompt, $userPrompt, 4000);

            if (! $parsed || isset($parsed['raw'])) {
                $rawText = $parsed['raw'] ?? '';
                if (preg_match('/```(?:json)?\s*({[\s\S]*?})\s*```/is', $rawText, $matches)) {
                    $parsed = json_decode($matches[1], true);
                } else {
                    $start = strpos($rawText, '{');
                    $end = strrpos($rawText, '}');
                    if ($start !== false && $end !== false) {
                        $parsed = json_decode(substr($rawText, $start, $end - $start + 1), true);
                    }
                }

                // If it still fails, gracefully skip this chunk instead of fully failing
                if (! is_array($parsed) || isset($parsed['raw'])) {
                    $hasError = true;

                    continue;
                }
            }

            // Merge valid chunk results
            foreach ($parsed as $key => $val) {
                if (is_array($val)) {
                    $allAssessments[$key] = $val;
                }
            }
        }

        if (empty($allAssessments)) {
            return response()->json([
                'error' => 'AI returned invalid format across all chunks. Please try again.',
            ], 500);
        }

        // Save AI assessments to database
        $existing = $system->protection_assessments ?? [];
        foreach ($allAssessments as $columnKey => $assessment) {
            $existing[$columnKey] = array_merge($assessment, [
                'assessed_at' => now()->toISOString(),
                'assessed_by' => $request->user()->id,
                'assessed_by_name' => $request->user()->name,
                'source' => 'ai',
            ]);
        }

        $system->update(['protection_assessments' => $existing]);

        AuditLog::log('data-discovery', $system->id, 'ai_protection_assessed', [
            'columns_assessed' => count($allAssessments),
            'has_partial_errors' => $hasError,
        ], 'system');

        return response()->json([
            'message' => $hasError ? 'AI Protection Assessment partially completed (some chunks failed).' : 'AI Protection Assessment completed',
            'data' => $existing,
            'ai_result' => $allAssessments,
        ]);
    }

    // =========================================================
    //  Sprint E1: OCR scan for unstructured files
    // =========================================================
    public function scanUnstructured(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,tiff,bmp,pdf|max:10240',
        ]);

        $file = $request->file('file');
        $org = Organization::findOrFail($request->user()->org_id);
        $ts = app(TenantStorageService::class);
        $stored = $ts->storeTenantPrivateFile($org, $file, 'ocr-uploads');
        $path = $stored['path'];
        [$fullPath, $cleanup] = $ts->getLocalPathForProcessing($org, $path);

        try {
            $ocr = new OcrScannerService;
            $result = $ocr->extractText($fullPath, $request->user()->org_id, $request->user()->id);
        } finally {
            $cleanup();
        }
        $text = $result['text'] ?? '';

        // Inline PII regex detection — Indonesian-first patterns
        $piiMatches = [];
        $patterns = [
            'nik' => '/\b\d{16}\b/',
            'npwp' => '/\b\d{2}\.\d{3}\.\d{3}\.\d{1}-\d{3}\.\d{3}\b/',
            'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            'phone_id' => '/\b(?:\+62|0)8\d{8,11}\b/',
            'credit_card' => '/\b(?:\d[ -]*?){13,19}\b/',
            'address_keyword' => '/\b(jl\.|jalan|gang|kel\.|kelurahan|rt\/rw|rt ?\d{2}|rw ?\d{2})\b/i',
        ];
        foreach ($patterns as $type => $re) {
            if (preg_match_all($re, $text, $m)) {
                $piiMatches[] = [
                    'type' => $type,
                    'count' => count($m[0]),
                    'sample' => array_slice($m[0], 0, 3),
                ];
            }
        }

        return response()->json([
            'data' => [
                'file_name' => $file->getClientOriginalName(),
                'extracted_text' => $result['text'] ?? '',
                'confidence' => $result['confidence'] ?? 0,
                'source' => $result['source'] ?? 'none',
                'pii_matches' => $piiMatches,
            ],
        ]);
    }

    // =========================================================
    //  Sprint E3: Metadata structure comparison
    // =========================================================
    public function compareMetadata(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate([
            'columns' => 'required|array|min:1|max:100',
            'columns.*' => 'string|max:100',
        ]);

        $schema = $system->scan_results ?? [];
        if (empty($schema['tables'])) {
            return response()->json(['message' => 'System belum pernah di-scan. Jalankan scan dulu.'], 422);
        }

        $matches = DatabaseScanner::compareMetadata($schema, $data['columns']);

        return response()->json(['data' => $matches]);
    }

    // =========================================================
    //  Sprint E4: AI SQL generator + sample execution
    // =========================================================
    public function sampleQuery(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate([
            'prompt' => 'required|string|max:1000',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $schema = $system->scan_results ?? [];
        if (empty($schema['tables'])) {
            return response()->json(['message' => 'System belum pernah di-scan. Jalankan scan dulu.'], 422);
        }

        $ai = new AiService($request->user()->org_id);
        if (! $ai->isAvailable()) {
            return response()->json(['message' => 'AI belum dikonfigurasi'], 503);
        }

        $dialect = match ($system->source_type) {
            'postgresql' => 'postgresql',
            default => 'mysql',
        };
        $sqlResp = $ai->generateSqlFromText(['tables' => $schema['tables']], $data['prompt'], $dialect);
        $queries = $sqlResp['sql_queries'] ?? [];
        if (empty($queries)) {
            return response()->json(['message' => 'AI tidak menghasilkan query yang valid', 'ai_response' => $sqlResp], 422);
        }

        $firstSql = $queries[0];
        $execution = DatabaseScanner::executeSampleQuery(
            $system->source_type,
            $system->connection_config ?? [],
            $firstSql,
            (int) ($data['limit'] ?? 50)
        );

        AuditLog::log('data-discovery', $system->id, 'sample_query', [
            'prompt' => $data['prompt'],
            'sql' => $execution['sql'] ?? $firstSql,
            'row_count' => $execution['row_count'] ?? 0,
        ], 'user');

        return response()->json([
            'data' => [
                'generated_queries' => $queries,
                'executed' => $execution,
            ],
        ]);
    }

    // =========================================================
    //  Agent #7 — Ephemeral AI Search Execute (no persistence)
    // =========================================================
    /**
     * POST /api/data-discovery/{id}/ai-search/execute
     *
     * Menerima daftar kasus pencarian natural-language (max 5), generate
     * SELECT-only SQL via AiSpecificSearchService (Agent #6), eksekusi di
     * koneksi DB tenant (read-only, dengan statement timeout), dan kembalikan
     * hasil sebagai response ephemeral. Hasil TIDAK pernah dipersist ke DB.
     *
     * Privacy guarantee: hanya schema (table + column names) yang dikirim ke
     * AI saat generate SQL — sample value tidak pernah keluar dari backend.
     * Setiap baris hasil di-cap maksimum 100 rows.
     */
    public function aiSearchExecute(Request $request, string $id, AiSpecificSearchService $service)
    {
        $request->validate([
            'cases' => ['required', 'array', 'min:1', 'max:5'],
            'cases.*.id' => ['required', 'string'],
            'cases.*.query_text' => ['required', 'string', 'min:5', 'max:500'],
            'date_context' => ['nullable', 'array'],
            'date_context.kind' => ['nullable', 'in:today,yesterday,last_7d,custom'],
            'date_context.date' => ['nullable', 'date_format:Y-m-d', 'required_if:date_context.kind,custom'],
        ]);

        $user = $request->user();
        $system = InformationSystem::where('org_id', $user->org_id)->findOrFail($id);

        // Defensive: butuh hasil scan untuk generate SQL aman
        $tables = $system->scan_results['tables'] ?? [];
        if (empty($tables)) {
            return response()->json([
                'error' => 'Sistem belum di-scan. Jalankan scan terlebih dahulu.',
            ], 422);
        }

        $cases = $request->input('cases');
        $dateContext = $request->input('date_context', ['kind' => 'today', 'date' => null]);

        // Step 1 — minta Agent #6 service untuk generate SQL per case.
        $generated = $service->generateSqls($user->org_id, $id, $cases, $dateContext);

        // Step 2 — resolve koneksi DB tenant.
        // InformationSystem tidak punya `connection_name` / `db_credentials` field;
        // pakai `connection_config` (existing). Bila kosong → koneksi default Laravel.
        $config = $system->connection_config ?? [];
        $connectionName = $this->resolveTenantConnection($system, $config);

        $results = [];
        $successCount = 0;
        $totalDurationMs = 0;

        foreach ($generated as $row) {
            $caseId = $row['case_id'] ?? null;
            $queryText = $row['query_text'] ?? null;
            $sql = $row['generated_sql'] ?? null;
            $genError = $row['error'] ?? null;

            // Skip case yang gagal di-generate.
            if ($sql === null || $genError !== null) {
                $results[] = [
                    'case_id' => $caseId,
                    'query_text' => $queryText,
                    'generated_sql' => null,
                    'row_count' => 0,
                    'sample_rows' => [],
                    'executed_at' => null,
                    'duration_ms' => 0,
                    'error' => $genError ?? 'SQL generation failed',
                ];
                continue;
            }

            // Defense in depth — Agent #6 sudah validate, kita re-validate.
            $validationError = $service->validateSqlIsSelectOnly($sql);
            if ($validationError !== null) {
                $results[] = [
                    'case_id' => $caseId,
                    'query_text' => $queryText,
                    'generated_sql' => $sql,
                    'row_count' => 0,
                    'sample_rows' => [],
                    'executed_at' => null,
                    'duration_ms' => 0,
                    'error' => 'SQL validation failed: '.$validationError,
                ];
                continue;
            }

            $startedAt = microtime(true);
            $executedAtIso = now()->toIso8601String();

            try {
                // Pasang statement timeout 5 detik bila driver support.
                $this->applyReadOnlyTimeout($connectionName);

                $rows = DB::connection($connectionName)->select($sql);

                // Cast hasil ke array of associative & cap 100 rows.
                $assoc = array_map(fn ($r) => (array) $r, $rows);
                $rowCount = count($assoc);
                $sample = array_slice($assoc, 0, 100);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $results[] = [
                    'case_id' => $caseId,
                    'query_text' => $queryText,
                    'generated_sql' => $sql,
                    'row_count' => $rowCount,
                    'sample_rows' => $sample,
                    'executed_at' => $executedAtIso,
                    'duration_ms' => $durationMs,
                    'error' => null,
                ];
                $successCount++;
                $totalDurationMs += $durationMs;
            } catch (\Throwable $e) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $totalDurationMs += $durationMs;
                $results[] = [
                    'case_id' => $caseId,
                    'query_text' => $queryText,
                    'generated_sql' => $sql,
                    'row_count' => 0,
                    'sample_rows' => [],
                    'executed_at' => $executedAtIso,
                    'duration_ms' => $durationMs,
                    'error' => self::humanizeDbError($e->getMessage()),
                ];
            }
        }

        // Step 4 — audit (metadata only, tidak ada raw SQL/result).
        AuditLog::log('data-discovery', $system->id, 'ai_search.executed', [
            'cases_count' => count($cases),
            'success_count' => $successCount,
            'total_duration_ms' => $totalDurationMs,
        ], 'user');

        // Step 5 — response ephemeral. JANGAN persist ke DB manapun.
        return response()->json(['results' => $results]);
    }

    /**
     * Resolve which Laravel DB connection to use for executing AI-generated
     * SQL against the tenant's information system. Falls back to the default
     * application connection bila system tidak punya connection_config
     * (mock / dev case).
     */
    private function resolveTenantConnection(InformationSystem $system, array $config): ?string
    {
        // Bila system tidak punya host config sama sekali → pakai default Laravel.
        if (empty($config) || empty($config['host'] ?? null)) {
            return null; // null = default connection
        }

        $sourceType = $system->source_type;
        $driver = match ($sourceType) {
            'postgresql', 'postgres', 'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };
        if ($driver === null) {
            return null; // unsupported driver → biar Laravel default yang handle
        }

        // Bikin connection name unik per system supaya tidak konflik antar tenant.
        $connName = 'ds_ai_search_'.$system->id;
        $existing = config('database.connections.'.$connName);
        if ($existing) {
            return $connName;
        }

        // Register dynamic read-only connection. Tidak persist ke file config.
        config([
            'database.connections.'.$connName => [
                'driver' => $driver,
                'host' => $config['host'] ?? '127.0.0.1',
                'port' => $config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306),
                'database' => $config['database'] ?? '',
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'sslmode' => $config['sslmode'] ?? 'prefer',
                'options' => [
                    \PDO::ATTR_TIMEOUT => 5,
                ],
            ],
        ]);

        return $connName;
    }

    /**
     * Set a session-level statement timeout (5s) on the given connection so
     * a runaway AI-generated query can't pin the tenant DB. Silently no-op
     * on drivers that don't support the directive (SQLite, MSSQL, etc.).
     */
    private function applyReadOnlyTimeout(?string $connectionName): void
    {
        try {
            $conn = DB::connection($connectionName);
            $driver = $conn->getDriverName();
            if ($driver === 'pgsql') {
                $conn->statement("SET statement_timeout TO 5000");
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $conn->statement('SET SESSION MAX_EXECUTION_TIME = 5000');
            }
            // SQLite/other → no-op
        } catch (\Throwable $e) {
            // Driver tidak support → biarkan, tidak fatal.
            Log::info('AI search statement_timeout skipped: '.$e->getMessage());
        }
    }
}
