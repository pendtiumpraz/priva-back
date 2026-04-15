<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint A2: Add composite indexes for performance optimization.
 * These indexes speed up the most common queries (filter by org_id + status/created_at).
 * Compatible with both MySQL and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RoPA: org_id + status (used in list, dashboard stats)
        $this->safeAddIndex('ropas', ['org_id', 'status'], 'ropas_org_status_idx');

        // DPIA: org_id + status
        $this->safeAddIndex('dpias', ['org_id', 'status'], 'dpias_org_status_idx');

        // Breach: org_id + status (used heavily in dashboard)
        $this->safeAddIndex('breach_incidents', ['org_id', 'status'], 'breaches_org_status_idx');

        // DSR: org_id + status
        $this->safeAddIndex('dsr_requests', ['org_id', 'status'], 'dsr_org_status_idx');

        // AI Results: org_id + feature_type + record_id (used in history lookup)
        $this->safeAddIndex('ai_results', ['org_id', 'feature_type', 'record_id'], 'ai_results_org_feature_record_idx');

        // AI Results: org_id + created_at (used in audit/usage reports)
        $this->safeAddIndex('ai_results', ['org_id', 'created_at'], 'ai_results_org_created_idx');

        // Gap Assessments: org_id + created_at
        $this->safeAddIndex('gap_assessments', ['org_id', 'created_at'], 'gap_org_created_idx');

        // Audit Logs: module + created_at (heavy query in log analyzer)
        // Note: audit_logs does NOT have org_id — uses module + created_at instead
        $this->safeAddIndex('audit_logs', ['module', 'created_at'], 'audit_module_created_idx');
    }

    public function down(): void
    {
        $indexes = [
            'ropas' => 'ropas_org_status_idx',
            'dpias' => 'dpias_org_status_idx',
            'breach_incidents' => 'breaches_org_status_idx',
            'dsr_requests' => 'dsr_org_status_idx',
            'ai_results' => ['ai_results_org_feature_record_idx', 'ai_results_org_created_idx'],
            'gap_assessments' => 'gap_org_created_idx',
            'audit_logs' => 'audit_module_created_idx',
        ];

        foreach ($indexes as $table => $idxNames) {
            if (Schema::hasTable($table)) {
                $names = is_array($idxNames) ? $idxNames : [$idxNames];
                foreach ($names as $name) {
                    $this->safeDropIndex($table, $name);
                }
            }
        }
    }

    /**
     * Safely add an index — checks table + columns exist and index not yet created.
     * Works on both MySQL and PostgreSQL.
     */
    private function safeAddIndex(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table)) return;

        // Verify all columns exist in the table
        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) return;
        }

        // Check if index already exists
        if ($this->indexExists($table, $indexName)) return;

        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        } catch (\Exception $e) {
            // Silently skip — index may already exist from a previous partial run
        }
    }

    /**
     * Safely drop an index — catches errors if index doesn't exist.
     */
    private function safeDropIndex(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        } catch (\Exception $e) {
            // Skip if index doesn't exist
        }
    }

    /**
     * Check if an index exists. Works on both MySQL and PostgreSQL.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        try {
            if ($driver === 'pgsql') {
                // PostgreSQL: query pg_indexes
                $result = DB::select(
                    "SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                    [$table, $indexName]
                );
            } else {
                // MySQL / MariaDB: query information_schema.statistics
                $db = Schema::getConnection()->getDatabaseName();
                $result = DB::select(
                    "SELECT COUNT(*) as cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                    [$db, $table, $indexName]
                );
            }
            return ($result[0]->cnt ?? 0) > 0;
        } catch (\Exception $e) {
            // If we can't check, assume it exists to avoid duplicate errors
            return true;
        }
    }
};
