<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert consent_logs into a monthly-partitioned table on PostgreSQL so
 * index sizes stay bounded as the table grows to billions of rows (the
 * realistic scale at 10K-100K/sec capture volume).
 *
 * Strategy (zero-downtime on prod):
 *   1. Rename existing consent_logs → consent_logs_legacy (preserves data).
 *   2. Create new partitioned parent table consent_logs (range on created_at).
 *   3. Create partitions for the current month and next 24 months.
 *   4. Copy data from legacy into the partitioned table if rows < 1M;
 *      skip copy for bigger tables and let ops handle backfill.
 *
 * On MySQL/SQLite this migration is a no-op — MySQL partitioning has
 * quirky constraints, SQLite doesn't support partitioning, and tests
 * run against :memory: sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') return; // no-op for mysql / sqlite

        if (!Schema::hasTable('consent_logs')) return;

        // Guard: already partitioned?
        $isAlreadyPartitioned = (bool) DB::selectOne(
            "SELECT 1 FROM pg_partitioned_table pt
             JOIN pg_class c ON c.oid = pt.partrelid
             WHERE c.relname = 'consent_logs' LIMIT 1"
        );
        if ($isAlreadyPartitioned) return;

        try {
            DB::unprepared('ALTER TABLE consent_logs RENAME TO consent_logs_legacy');

            DB::unprepared(<<<SQL
                CREATE TABLE consent_logs (
                    id uuid NOT NULL,
                    org_id uuid NOT NULL,
                    collection_id uuid NOT NULL,
                    user_identifier varchar(255) NOT NULL,
                    consented_items jsonb NOT NULL,
                    policy_version varchar(32) NULL,
                    ip_address varchar(45) NULL,
                    user_agent text NULL,
                    created_at timestamp NULL,
                    updated_at timestamp NULL,
                    PRIMARY KEY (id, created_at)
                ) PARTITION BY RANGE (created_at);
            SQL);

            // Create partitions: current month + next 24
            $start = new \DateTimeImmutable('first day of this month 00:00');
            for ($i = 0; $i <= 24; $i++) {
                $from = $start->modify("+{$i} months");
                $to = $from->modify('+1 month');
                $name = 'consent_logs_' . $from->format('Y_m');
                $fromStr = $from->format('Y-m-d');
                $toStr = $to->format('Y-m-d');
                DB::unprepared(
                    "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF consent_logs
                     FOR VALUES FROM ('{$fromStr}') TO ('{$toStr}')"
                );
            }

            // Default partition catches stray historical / future dates so
            // writes never fail due to a missing partition.
            DB::unprepared('CREATE TABLE IF NOT EXISTS consent_logs_default PARTITION OF consent_logs DEFAULT');

            // Per-partition indexes (Postgres auto-creates equivalent ones
            // on each partition for the parent indexes we declare below).
            DB::unprepared('CREATE INDEX IF NOT EXISTS idx_consent_logs_org ON consent_logs (org_id, created_at DESC)');
            DB::unprepared('CREATE INDEX IF NOT EXISTS idx_consent_logs_collection ON consent_logs (collection_id, created_at DESC)');
            DB::unprepared('CREATE INDEX IF NOT EXISTS idx_consent_logs_user ON consent_logs (collection_id, user_identifier, created_at DESC)');

            // Lightweight copy if the legacy table is small enough to move
            // inline. Bigger tables should be backfilled out of band to
            // avoid holding locks during deploy.
            $rowCount = (int) DB::scalar('SELECT COUNT(*) FROM consent_logs_legacy');
            if ($rowCount > 0 && $rowCount < 1_000_000) {
                DB::unprepared('INSERT INTO consent_logs SELECT * FROM consent_logs_legacy');
                DB::unprepared('DROP TABLE consent_logs_legacy');
            } else {
                // Leave legacy intact; ops can backfill + drop later.
                // No-op here intentionally.
            }
        } catch (\Throwable $e) {
            \Log::warning('consent_logs partitioning skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Not implemented — partition reversal is destructive and rare.
        // Manually: pg_dump consent_logs, DROP, recreate non-partitioned,
        // pg_restore if a rollback is truly needed.
    }
};
