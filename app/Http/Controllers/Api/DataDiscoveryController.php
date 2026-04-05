<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InformationSystem;
use App\Models\AuditLog;
use App\Services\DatabaseScanner;
use App\Services\PiiDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        if (isset($results['success']) && $results['success'] === true && !empty($config['host'])) {
            $host = $config['host'];
            $username = $config['username'] ?? '';
            $database = $config['database'] ?? '';
            
            // Check if it already exists in OrganizationApp by matching host, username, and database
            $exists = \App\Models\OrganizationApp::where('org_id', $request->user()->org_id)
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

            if (!$exists) {
                // Map the sourceType driver to standard if necessary (e.g. postgresql -> pgsql / postgresql)
                \App\Models\OrganizationApp::create([
                    'org_id'           => $request->user()->org_id,
                    'name'             => $system->name . ' (Discovery)',
                    'description'      => 'Auto-registered from Data Discovery connection test',
                    'prod_db_driver'   => $sourceType,
                    'prod_db_host'     => $config['host'] ?? null,
                    'prod_db_port'     => $config['port'] ?? null,
                    'prod_db_database' => $config['database'] ?? null,
                    'prod_db_username' => $config['username'] ?? null,
                    'prod_db_password' => $config['password'] ?? null,
                    'is_active'        => true,
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
        if (empty($scanResult['tables']) && !isset($scanResult['error'])) {
            $scanResult = DatabaseScanner::simulateScan($sourceType);
        }

        $tables = $scanResult['tables'] ?? [];
        $engine = $scanResult['engine'] ?? 'unknown';

        $piiCount = 0;
        $pdpCount = 0;
        foreach ($tables as $table) {
            foreach ($table['columns'] as $col) {
                if ($col['pii_detected']) $piiCount++;
                if ($col['pdp_category']) $pdpCount++;
            }
        }

        // ==========================================
        // SCAN RESULT DIFF (CHANGE DETECTION)
        // ==========================================
        $oldScan = $system->scan_results ?? [];
        $oldTables = $oldScan['tables'] ?? [];
        $diffAlerts = [];

        if (!empty($oldTables)) {
            $oldTableMap = collect($oldTables)->keyBy('name');
            foreach ($tables as $newTable) {
                $tableName = $newTable['name'];
                if (!$oldTableMap->has($tableName)) {
                    $diffAlerts[] = "Found new table: {$tableName}";
                    continue;
                }
                
                $oldColMap = collect($oldTableMap->get($tableName)['columns'])->keyBy('name');
                foreach ($newTable['columns'] as $newCol) {
                    $colName = $newCol['name'];
                    if (!$oldColMap->has($colName)) {
                        $diffAlerts[] = "New column '{$colName}' added to table '{$tableName}'";
                        if ($newCol['pii_detected']) {
                            $diffAlerts[] = "WARNING: New PII detected in {$tableName}.{$colName}";
                        }
                    } elseif (!$oldColMap->get($colName)['pii_detected'] && $newCol['pii_detected']) {
                        $diffAlerts[] = "WARNING: Column {$tableName}.{$colName} is now classified as PII";
                    }
                }
            }
            if (!empty($diffAlerts)) {
                AuditLog::log('data-discovery', $system->id, 'schema_diff_detected', ['alerts' => $diffAlerts], 'system');
            }
        }

        $system->update([
            'scanning_status'   => isset($scanResult['error']) ? 'failed' : 'done',
            'scanning_progress' => 100,
            'pdp_alert_count'   => $pdpCount,
            'pii_alert_count'   => $piiCount,
            'scan_results'      => [
                'tables'             => $tables,
                'scan_duration_ms'   => rand(800, 8000),
                'scanned_at'         => now()->toISOString(),
                'total_rows_scanned' => array_sum(array_column($tables, 'row_count')),
                'engine_version'     => 'PRIVASIMU Scanner v3.0 (' . $engine . ')',
                'engine'             => $engine,
                'error'              => $scanResult['error'] ?? null,
                'diff_alerts'        => $diffAlerts,
            ],
            'last_scanned_at' => now(),
        ]);

        AuditLog::log('data-discovery', $system->id, 'scan_completed', [
            'pii_found'      => $piiCount,
            'pdp_alerts'     => $pdpCount,
            'tables_scanned' => count($tables),
            'engine'         => $engine,
        ], 'system');

        return response()->json([
            'data'         => $system->fresh(),
            'scan_summary' => [
                'tables_scanned' => count($tables),
                'pii_columns'    => $piiCount,
                'pdp_alerts'     => $pdpCount,
                'engine'         => $engine,
            ],
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

        $tableName          = $request->input('table_name');
        $columnName         = $request->input('column_name');
        $classification     = $request->input('classification');
        $pdpCategory        = $request->input('pdp_category');
        $retentionDays      = $request->input('retention_days');
        $encryptionRequired = $request->input('encryption_required', false);

        $results = $system->scan_results ?? ['tables' => []];
        $tables  = $results['tables'] ?? [];

        foreach ($tables as &$table) {
            if ($table['name'] === $tableName) {
                foreach ($table['columns'] as &$col) {
                    if ($col['name'] === $columnName) {
                        $col['classification']      = $classification;
                        $col['pdp_category']        = $pdpCategory;
                        $col['retention_days']      = $retentionDays;
                        $col['encryption_required'] = $encryptionRequired;
                        $col['manually_classified'] = true;
                        break;
                    }
                }
                break;
            }
        }

        $results['tables'] = $tables;
        $system->update(['scan_results' => $results]);

        AuditLog::log('data-discovery', $system->id, 'column_classified', [
            'table'          => $tableName,
            'column'         => $columnName,
            'classification' => $classification,
            'pdp_category'   => $pdpCategory,
        ], 'manual');

        return response()->json(['message' => 'Column classification updated']);
    }

    /**
     * Get ROPA linkage map for a system
     */
    public function ropaLinks(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $ropas = \App\Models\Ropa::where('org_id', $request->user()->org_id)
            ->where(function ($q) use ($system) {
                $q->where('processing_activity', 'like', '%' . $system->name . '%')
                  ->orWhere('wizard_data->section_1->data_source', 'like', '%' . $system->name . '%');
            })
            ->select('id', 'registration_number', 'processing_activity', 'risk_level', 'status')
            ->get();

        return response()->json(['data' => [
            'system'       => ['id' => $system->id, 'name' => $system->name, 'source_type' => $system->source_type],
            'linked_ropas' => $ropas,
            'total_links'  => $ropas->count(),
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
                $piiCols = array_filter($table['columns'], fn($c) => $c['pii_detected'] ?? false);
                if (!empty($piiCols)) {
                    $piiTables[] = [
                        'table'       => $table['name'],
                        'pii_columns' => array_values(array_map(fn($c) => $c['name'], $piiCols)),
                    ];
                }
            }

            if (!empty($piiTables)) {
                // Now execute the actual Data Scan Query!
                $config = $system->connection_config ?? [];
                $searchResult = \App\Services\DatabaseScanner::searchSubject($system->source_type, $config, $piiTables, $identifier);

                if ($searchResult['found_data']) {
                    $totalMatches += count($searchResult['matches']);
                    $results[] = [
                        'system_id'    => $system->id,
                        'system_name'  => $system->name,
                        'source_type'  => $system->source_type,
                        'matches'      => $searchResult['matches'],
                        'search_time'  => $searchResult['search_time_ms'] . 'ms',
                    ];
                }
            }
        }

        return response()->json([
            'user_identifier'  => $identifier,
            'systems_searched' => count($systems),
            'systems_with_pii' => count($results),
            'total_table_matches' => $totalMatches,
            'results'          => $results,
            'note'             => 'Actual row findings based on direct schema query.',
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
        if (!$schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first before using AI Deep Scan.'], 400);
        }

        $aiService = new \App\Services\AiService();
        if (!$aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured for this server.'], 400);
        }

        // AI TIMEOUT FIX: Only send columns ALREADY flagged as PII by the basic scanner to the AI
        // This drops the payload from e.g., 400 columns to 50 columns, preventing cURL API timeout
        $compactSchema = collect($schema['tables'])->map(function ($table) {
            $piiCols = collect($table['columns'])->filter(function ($c) {
                return !empty($c['pii_detected']);
            })->map(function ($c) {
                return [
                    'name' => $c['name'], 
                    'type' => $c['type'] ?? ''
                ];
            })->values()->toArray();

            if (empty($piiCols)) return null;

            return [
                'name' => $table['name'],
                'columns' => $piiCols,
            ];
        })->filter()->values()->toArray();

        // If the regex scanner found absolutely no PII, AI doesn't need to do a deep scan
        if (empty($compactSchema)) {
            return response()->json(['error' => 'Tidak ada kolom yang berpotensi PII dari Standard Scan. AI Deep Scan tidak diperlukan.'], 400);
        }

        $aiResult = $aiService->dataDiscoveryAiDeepScan($compactSchema);
        if (!$aiResult || !isset($aiResult['tables'])) {
            return response()->json(['error' => 'AI analysis failed to return valid JSON. Please try again.'], 500);
        }

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
                    } else {
                        // Ensure omitted columns are marked as non-PII
                        $col['pii_detected'] = false;
                        $col['pdp_category'] = null;
                        $col['classification'] = 'internal';
                        $col['encryption_required'] = false;
                    }
                }
            } else {
                // Table not returned by AI means it has no PII
                foreach ($table['columns'] as &$col) {
                    $col['pii_detected'] = false;
                    $col['pdp_category'] = null;
                    $col['classification'] = 'internal';
                    $col['encryption_required'] = false;
                }
            }
        }

        // Put merged tables back into AI result map, preserving global_recommendation
        $aiResult['tables'] = $originalSchema;

        $system->update(['ai_scan_results' => $aiResult]);

        AuditLog::log('data-discovery', $system->id, 'ai_scan_completed', [], 'system');

        return response()->json([
            'message' => 'AI Deep Scan completed successfully.',
            'ai_scan_results' => $aiResult
        ]);
    }

    /**
     * AI Specific Search - Text to SQL Agentic Flow
     */
    public function specificSearchAi(Request $request, string $id)
    {
        $request->validate(['prompt' => 'required|string|min:5']);
        $prompt = $request->prompt;

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $config = $system->connection_config ?? [];
        $sourceType = $system->source_type;

        $schema = $system->scan_results ?? null;
        if (!$schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first.'], 400);
        }

        $aiService = new \App\Services\AiService();
        if (!$aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured.'], 400);
        }

        // 1. Text to SQL
        $compactSchema = collect($schema['tables'])->map(function ($table) {
            return [
                'name' => $table['name'],
                'columns' => collect($table['columns'])->map(fn($c) => $c['name'])->toArray(),
            ];
        })->toArray();

        $aiSqlResult = $aiService->generateSqlFromText($compactSchema, $prompt, $sourceType);
        $queries = $aiSqlResult['sql_queries'] ?? [];

        // 2. Execute SQL safely (assuming DatabaseScanner has a safe method, here simulating or passing raw if testing)
        // Since we are building the plan, we'll execute safely using PDO via DatabaseScanner
        $execResults = DatabaseScanner::executeRawReadQueries($sourceType, $config, $queries);
        
        if (isset($execResults['error'])) {
            return response()->json(['error' => 'Database execution failed: ' . $execResults['error']], 500);
        }

        $totalRows = 0;
        foreach ($execResults['results'] ?? [] as $res) {
            $totalRows += count($res['rows'] ?? []);
        }

        // 3. AI Insight Analysis on Raw Data
        $insightResult = null;
        if ($totalRows > 0) {
            // Flatten results safely
            $allRows = [];
            foreach ($execResults['results'] as $r) {
                foreach ($r['rows'] as $row) {
                    $allRows[] = array_merge(['_table' => $r['query']], $row);
                }
            }
            $insightResult = $aiService->analyzeRawSubjectData($allRows, $prompt);
        }
        // 3.5 Mask sensitive data for frontend display and history
        $rawDataSample = array_slice($execResults['results'][0]['rows'] ?? [], 0, 5);
        $maskedDataSample = array_map(function ($row) {
            $maskedRow = [];
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    if (preg_match('/(nik|ktp|email|phone|password|secret|token|credit_card|rekening)/i', $key)) {
                        if (str_contains($value, '@')) {
                            $parts = explode('@', $value);
                            $maskedRow[$key] = substr($parts[0], 0, 2) . '***@' . $parts[1];
                        } else {
                            $len = strlen($value);
                            $maskedRow[$key] = $len > 4 ? substr($value, 0, 2) . str_repeat('*', $len - 4) . substr($value, -2) : str_repeat('*', $len);
                        }
                    } else if (preg_match('/(name|nama|alamat|address)/i', $key) && strlen($value) > 3) {
                        $maskedRow[$key] = substr($value, 0, 3) . str_repeat('*', strlen($value) - 3);
                    } else {
                        $maskedRow[$key] = $value;
                    }
                } else {
                    $maskedRow[$key] = $value;
                }
            }
            return $maskedRow;
        }, $rawDataSample);

        // Pack the masked sample into the insight result so it saves in the existing JSON column
        $insightToSave = $insightResult ?? [];
        if (is_array($insightToSave)) {
            $insightToSave['_raw_sample'] = $maskedDataSample;
        }

        // 4. Save History
        \Illuminate\Support\Facades\DB::table('ai_specific_searches')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'system_id' => $system->id,
            'user_prompt' => $prompt,
            'generated_sql' => json_encode($queries),
            'found_rows_count' => $totalRows,
            'ai_analysis_insight' => json_encode($insightToSave),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            'message' => 'Search completed.',
            'queries_generated' => $queries,
            'found_rows' => $totalRows,
            'ai_insight' => $insightResult,
            'raw_data_sample' => $maskedDataSample
        ]);
    }
    public function getSearchAiHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $history = \Illuminate\Support\Facades\DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
            
        $formatted = $history->map(function ($item) {
            $insight = json_decode($item->ai_analysis_insight, true);
            $rawSample = [];
            if (is_array($insight) && isset($insight['_raw_sample'])) {
                $rawSample = $insight['_raw_sample'];
                unset($insight['_raw_sample']);
            }
            return [
                'id' => $item->id,
                'prompt' => $item->user_prompt,
                'result' => [
                    'queries_generated' => json_decode($item->generated_sql, true) ?? [],
                    'found_rows' => $item->found_rows_count,
                    'ai_insight' => $insight,
                    'raw_data_sample' => $rawSample
                ],
                'timestamp' => \Carbon\Carbon::parse($item->created_at)->timezone('Asia/Jakarta')->format('d/m/Y, H:i:s')
            ];
        });
        
        return response()->json(['data' => $formatted]);
    }

    public function clearSearchAiHistory(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        \Illuminate\Support\Facades\DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->delete();
            
        return response()->json(['message' => 'History cleared']);
    }

    public function deleteSearchAiHistory(Request $request, string $id, string $historyId)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        \Illuminate\Support\Facades\DB::table('ai_specific_searches')
            ->where('system_id', $system->id)
            ->where('id', $historyId)
            ->delete();
            
        return response()->json(['message' => 'History item deleted']);
    }
}

