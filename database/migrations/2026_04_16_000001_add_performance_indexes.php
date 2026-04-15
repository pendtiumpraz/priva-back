<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint A2: Add composite indexes for performance optimization.
 * These indexes speed up the most common queries (filter by org_id + status/created_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        // RoPA: org_id + status (used in list, dashboard stats)
        if (Schema::hasTable('ropas') && !$this->indexExists('ropas', 'ropas_org_status_idx')) {
            Schema::table('ropas', function (Blueprint $table) {
                $table->index(['org_id', 'status'], 'ropas_org_status_idx');
            });
        }

        // DPIA: org_id + status
        if (Schema::hasTable('dpias') && !$this->indexExists('dpias', 'dpias_org_status_idx')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->index(['org_id', 'status'], 'dpias_org_status_idx');
            });
        }

        // Breach: org_id + status (used heavily in dashboard)
        if (Schema::hasTable('breach_incidents') && !$this->indexExists('breach_incidents', 'breaches_org_status_idx')) {
            Schema::table('breach_incidents', function (Blueprint $table) {
                $table->index(['org_id', 'status'], 'breaches_org_status_idx');
            });
        }

        // DSR: org_id + status
        if (Schema::hasTable('dsr_requests') && !$this->indexExists('dsr_requests', 'dsr_org_status_idx')) {
            Schema::table('dsr_requests', function (Blueprint $table) {
                $table->index(['org_id', 'status'], 'dsr_org_status_idx');
            });
        }

        // AI Results: org_id + feature_type + record_id (used in history lookup)
        if (Schema::hasTable('ai_results') && !$this->indexExists('ai_results', 'ai_results_org_feature_record_idx')) {
            Schema::table('ai_results', function (Blueprint $table) {
                $table->index(['org_id', 'feature_type', 'record_id'], 'ai_results_org_feature_record_idx');
            });
        }

        // AI Results: org_id + created_at (used in audit/usage reports)
        if (Schema::hasTable('ai_results') && !$this->indexExists('ai_results', 'ai_results_org_created_idx')) {
            Schema::table('ai_results', function (Blueprint $table) {
                $table->index(['org_id', 'created_at'], 'ai_results_org_created_idx');
            });
        }

        // Gap Assessments: org_id + created_at
        if (Schema::hasTable('gap_assessments') && !$this->indexExists('gap_assessments', 'gap_org_created_idx')) {
            Schema::table('gap_assessments', function (Blueprint $table) {
                $table->index(['org_id', 'created_at'], 'gap_org_created_idx');
            });
        }

        // Audit Logs: org_id + created_at (heavy query in log analyzer)
        if (Schema::hasTable('audit_logs') && !$this->indexExists('audit_logs', 'audit_org_created_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['org_id', 'created_at'], 'audit_org_created_idx');
            });
        }
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
            'audit_logs' => 'audit_org_created_idx',
        ];

        foreach ($indexes as $table => $idxNames) {
            if (Schema::hasTable($table)) {
                $names = is_array($idxNames) ? $idxNames : [$idxNames];
                Schema::table($table, function (Blueprint $t) use ($names) {
                    foreach ($names as $name) {
                        try { $t->dropIndex($name); } catch (\Exception $e) { /* skip */ }
                    }
                });
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $db = Schema::getConnection()->getDatabaseName();
            $result = \Illuminate\Support\Facades\DB::select(
                "SELECT COUNT(*) as cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$db, $table, $indexName]
            );
            return ($result[0]->cnt ?? 0) > 0;
        } catch (\Exception $e) {
            // If we can't check, assume it exists to avoid duplicate errors
            return true;
        }
    }
};
