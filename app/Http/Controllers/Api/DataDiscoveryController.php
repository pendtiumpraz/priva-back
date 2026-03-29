<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InformationSystem;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DataDiscoveryController extends Controller
{
    /**
     * Test database connection (simulated for presentation)
     */
    public function testConnection(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $sourceType = $system->source_type;
        $config = $system->connection_config ?? [];

        // Simulate connection test with realistic timings
        $results = match ($sourceType) {
            'postgresql' => [
                'success' => true,
                'latency_ms' => rand(12, 85),
                'server_version' => 'PostgreSQL 15.4',
                'tables_found' => rand(8, 45),
                'estimated_rows' => rand(50000, 5000000),
                'ssl_enabled' => true,
            ],
            'mysql' => [
                'success' => true,
                'latency_ms' => rand(8, 60),
                'server_version' => 'MySQL 8.0.35',
                'tables_found' => rand(5, 35),
                'estimated_rows' => rand(30000, 3000000),
                'ssl_enabled' => false,
            ],
            'mongodb' => [
                'success' => true,
                'latency_ms' => rand(15, 120),
                'server_version' => 'MongoDB 7.0.4',
                'collections_found' => rand(3, 20),
                'estimated_documents' => rand(100000, 10000000),
                'replica_set' => true,
            ],
            'api' => [
                'success' => true,
                'latency_ms' => rand(80, 300),
                'status_code' => 200,
                'content_type' => 'application/json',
                'endpoints_discovered' => rand(3, 12),
            ],
            'file' => [
                'success' => true,
                'latency_ms' => rand(5, 30),
                'files_found' => rand(10, 200),
                'total_size_mb' => rand(50, 5000),
                'formats' => ['csv', 'xlsx', 'json'],
            ],
            default => ['success' => false, 'error' => 'Unknown source type'],
        };

        // Update system record
        $system->update([
            'connection_config' => array_merge($config, ['last_test' => now()->toISOString(), 'test_result' => $results]),
        ]);

        AuditLog::log('data-discovery', $system->id, 'connection_tested', $results, 'system');

        return response()->json(['data' => $results]);
    }

    /**
     * Trigger PII scan (simulated with realistic column-level results)
     */
    public function triggerScan(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        // Generate realistic scan results with column-level PII detection
        $tables = $this->generateScanResults($system->source_type);

        $piiCount = 0;
        $pdpCount = 0;
        foreach ($tables as $table) {
            foreach ($table['columns'] as $col) {
                if ($col['pii_detected']) $piiCount++;
                if ($col['pdp_category']) $pdpCount++;
            }
        }

        $system->update([
            'scanning_status' => 'done',
            'scanning_progress' => 100,
            'pdp_alert_count' => $pdpCount,
            'pii_alert_count' => $piiCount,
            'scan_results' => [
                'tables' => $tables,
                'scan_duration_ms' => rand(3000, 45000),
                'scanned_at' => now()->toISOString(),
                'total_rows_scanned' => rand(10000, 500000),
                'engine_version' => 'PRIVASIMU Scanner v2.1',
            ],
            'last_scanned_at' => now(),
        ]);

        AuditLog::log('data-discovery', $system->id, 'scan_completed', [
            'pii_found' => $piiCount,
            'pdp_alerts' => $pdpCount,
            'tables_scanned' => count($tables),
        ], 'system');

        return response()->json([
            'data' => $system->fresh(),
            'scan_summary' => [
                'tables_scanned' => count($tables),
                'pii_columns' => $piiCount,
                'pdp_alerts' => $pdpCount,
            ],
        ]);
    }

    /**
     * Get column-level scan details for a system
     */
    public function scanDetails(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);
        $results = $system->scan_results ?? [];

        return response()->json(['data' => $results]);
    }

    /**
     * Update column classification (manual override)
     */
    public function updateColumnClassification(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        $tableName = $request->input('table_name');
        $columnName = $request->input('column_name');
        $classification = $request->input('classification'); // 'pii', 'sensitive', 'public', 'internal'
        $pdpCategory = $request->input('pdp_category'); // null, 'umum', 'spesifik'
        $retentionDays = $request->input('retention_days');
        $encryptionRequired = $request->input('encryption_required', false);

        $results = $system->scan_results ?? ['tables' => []];
        $tables = $results['tables'] ?? [];

        foreach ($tables as &$table) {
            if ($table['name'] === $tableName) {
                foreach ($table['columns'] as &$col) {
                    if ($col['name'] === $columnName) {
                        $col['classification'] = $classification;
                        $col['pdp_category'] = $pdpCategory;
                        $col['retention_days'] = $retentionDays;
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
            'table' => $tableName,
            'column' => $columnName,
            'classification' => $classification,
            'pdp_category' => $pdpCategory,
        ], 'manual');

        return response()->json(['message' => 'Column classification updated']);
    }

    /**
     * Get ROPA linkage map for a system
     */
    public function ropaLinks(Request $request, string $id)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($id);

        // Find ROPA records that reference this system
        $ropas = \App\Models\Ropa::where('org_id', $request->user()->org_id)
            ->where(function ($q) use ($system) {
                $q->where('processing_activity', 'like', '%' . $system->name . '%')
                  ->orWhere('wizard_data->section_1->data_source', 'like', '%' . $system->name . '%');
            })
            ->select('id', 'registration_number', 'processing_activity', 'risk_level', 'status')
            ->get();

        return response()->json(['data' => [
            'system' => [
                'id' => $system->id,
                'name' => $system->name,
                'source_type' => $system->source_type,
            ],
            'linked_ropas' => $ropas,
            'total_links' => $ropas->count(),
        ]]);
    }

    /* ===== Private Helpers ===== */

    private function generateScanResults(string $sourceType): array
    {
        $tableTemplates = [
            ['name' => 'users', 'columns' => [
                ['name' => 'id', 'type' => 'uuid', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'full_name', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false],
                ['name' => 'email', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false],
                ['name' => 'phone_number', 'type' => 'varchar(20)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false],
                ['name' => 'nik', 'type' => 'varchar(16)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true],
                ['name' => 'date_of_birth', 'type' => 'date', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii', 'encryption_required' => false],
                ['name' => 'created_at', 'type' => 'timestamp', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
            ]],
            ['name' => 'employees', 'columns' => [
                ['name' => 'id', 'type' => 'bigint', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'employee_name', 'type' => 'varchar(255)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii'],
                ['name' => 'salary', 'type' => 'decimal(15,2)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true],
                ['name' => 'health_record', 'type' => 'text', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true],
                ['name' => 'religion', 'type' => 'varchar(50)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true],
                ['name' => 'department', 'type' => 'varchar(100)', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
            ]],
            ['name' => 'transactions', 'columns' => [
                ['name' => 'id', 'type' => 'uuid', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'customer_id', 'type' => 'uuid', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'card_number', 'type' => 'varchar(19)', 'pii_detected' => true, 'pdp_category' => 'spesifik', 'classification' => 'sensitive', 'encryption_required' => true],
                ['name' => 'amount', 'type' => 'decimal(15,2)', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'ip_address', 'type' => 'varchar(45)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii'],
            ]],
            ['name' => 'audit_logs', 'columns' => [
                ['name' => 'id', 'type' => 'bigint', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
                ['name' => 'user_agent', 'type' => 'text', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii'],
                ['name' => 'ip_address', 'type' => 'varchar(45)', 'pii_detected' => true, 'pdp_category' => 'umum', 'classification' => 'pii'],
                ['name' => 'action', 'type' => 'varchar(100)', 'pii_detected' => false, 'pdp_category' => null, 'classification' => 'internal'],
            ]],
        ];

        // Return 2-4 random tables
        $count = rand(2, min(4, count($tableTemplates)));
        shuffle($tableTemplates);
        $selected = array_slice($tableTemplates, 0, $count);

        foreach ($selected as &$table) {
            $table['row_count'] = rand(500, 250000);
            $table['size_mb'] = round($table['row_count'] * rand(1, 8) / 1000, 1);
        }

        return $selected;
    }
}
