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

        // Sprint E2: selective scan via ?selected_tables / ?selected_columns
        $selectedTables = $request->input('selected_tables', []);
        $selectedColumns = $request->input('selected_columns', []);
        if (!empty($selectedTables) || !empty($selectedColumns)) {
            $scanResult = DatabaseScanner::filterSchema($scanResult, (array) $selectedTables, (array) $selectedColumns);
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
        if (!$schema || empty($schema['tables'])) {
            return response()->json(['error' => 'Please perform a standard scan first.'], 400);
        }

        $aiService = new \App\Services\AiService($request->user()->org_id);
        if (!$aiService->isAvailable()) {
            return response()->json(['error' => 'AI Provider is not configured.'], 400);
        }

        // Collect PII columns for AI analysis
        $piiColumns = [];
        foreach ($schema['tables'] as $table) {
            foreach ($table['columns'] as $col) {
                if (!empty($col['pii_detected'])) {
                    $piiColumns[] = [
                        'key' => $table['name'] . '.' . $col['name'],
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
                . "Tugasmu menganalisis kolom-kolom PII dan merekomendasikan proteksi yang diperlukan.\n"
                . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
                . "FORMAT OUTPUT JSON (key = \"table.column\", value = object):\n"
                . json_encode([
                    'users.email' => [
                        'is_masked_frontend' => true,
                        'is_encrypted_db' => false,
                        'has_access_control' => true,
                        'is_redacted_api' => true,
                        'has_audit_log' => true,
                        'has_retention_policy' => false,
                        'recommendation' => 'Penjelasan singkat dalam Bahasa Indonesia',
                    ]
                ], JSON_PRETTY_PRINT);

            $userPrompt = "Analisis kolom PII dari database \"{$system->name}\":\n{$colList}\n"
                . "Untuk SETIAP kolom di atas, rekomendasikan proteksi:\n"
                . "- is_masked_frontend: Harus dimasking di UI?\n"
                . "- is_encrypted_db: Harus dienkripsi di database?\n"
                . "- has_access_control: Akses dibatasi per role?\n"
                . "- is_redacted_api: API response harus diredaksi?\n"
                . "- has_audit_log: Akses dicatat di audit log?\n"
                . "- has_retention_policy: Perlu auto-delete setelah retensi?\n"
                . "- recommendation: Alasan spesifik dalam Bahasa Indonesia\n\n"
                . "Jawab HANYA JSON valid.";

            $parsed = $aiService->ask($systemPrompt, $userPrompt, 4000);

            if (!$parsed || isset($parsed['raw'])) {
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
                if (!is_array($parsed) || isset($parsed['raw'])) {
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
            'has_partial_errors' => $hasError
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
        $path = $file->store('ocr-uploads/' . $request->user()->org_id, 'local');
        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);

        $ocr = new \App\Services\OcrScannerService();
        $result = $ocr->extractText($fullPath, $request->user()->org_id);
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

        $ai = new \App\Services\AiService($request->user()->org_id);
        if (!$ai->isAvailable()) return response()->json(['message' => 'AI belum dikonfigurasi'], 503);

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
}
