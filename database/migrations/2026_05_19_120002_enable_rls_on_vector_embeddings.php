<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * RAG Infrastructure — Layer 5 Defense: Postgres Row Level Security on vector_embeddings.
 *
 * Defense-in-depth: bahkan kalau developer nulis raw query lupa `WHERE org_id = ?`,
 * Postgres RLS akan REJECT row di level engine. Ini adalah lapisan terakhir
 * setelah:
 *   1. BelongsToOrg trait + global scope di Eloquent model
 *   2. TenantContextService di service layer
 *   3. Controller-level org_id filter
 *   4. Cache key bermuatan org_id hash
 *   5. (this) Postgres RLS — last line of defense
 *
 * Cara kerja:
 *   - RLS policy match `org_id::text` terhadap session variable
 *     `app.current_org_id`. Variable ini WAJIB di-SET LOCAL per request
 *     oleh middleware (mis. `SetCurrentOrgContext`):
 *
 *         DB::statement("SET LOCAL app.current_org_id = ?", [$orgId]);
 *
 *   - Untuk operasi superadmin cross-tenant (audit, support, backup),
 *     set bypass flag via middleware khusus:
 *
 *         DB::statement("SET LOCAL app.bypass_rls = 'true'");
 *
 *   - `current_setting('...', true)` argumen kedua `true` = missing_ok,
 *     return NULL kalau setting belum di-set (bukan error). Penting supaya
 *     query yang dijalankan di luar request context (artisan command,
 *     scheduler) tidak fatal — tapi mereka akan return 0 rows kalau tidak
 *     di-bypass. Ini behavior yang diinginkan (fail-closed).
 *
 * ============================================================
 *  WARNING — REQUIRED FOLLOW-UP (di-handle di file lain):
 * ============================================================
 * Middleware `SetCurrentOrgContext` (atau equivalent di HTTP kernel) HARUS
 * di-update untuk mem-`SET LOCAL app.current_org_id` setiap request setelah
 * user/org context resolved. Tanpa ini, SEMUA query ke `vector_embeddings`
 * di Postgres akan return 0 rows karena RLS policy reject semua row
 * (NULL != org_id::text).
 *
 * Checklist konsumen yang perlu disesuaikan (lihat task lain, JANGAN edit
 * dari migration ini):
 *   - `app/Http/Middleware/SetCurrentOrgContext.php` (atau buat baru)
 *   - Console / scheduler context — set var atau bypass untuk job
 *     yang memang lintas-tenant
 *   - Queue worker — set var pada job handle berdasarkan job payload
 *
 * Idempotency:
 *   - `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` aman dipanggil ulang
 *     (di-wrap try/catch supaya migration tidak fatal di re-run).
 *   - Policy di-`DROP POLICY IF EXISTS` dulu sebelum `CREATE POLICY`.
 *
 * Cross-driver:
 *   - Postgres-only. SQLite/MySQL skip total — RLS bukan fitur driver tsb.
 *     Defense bergantung pada layer 1–4 di environment non-Postgres.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('vector_embeddings')) {
            return;
        }

        // Enable RLS — wrap try/catch supaya re-run migration tidak fatal
        // kalau RLS sudah enabled sebelumnya. Postgres tidak punya
        // "IF NOT ENABLED" syntax untuk RLS.
        try {
            DB::statement('ALTER TABLE vector_embeddings ENABLE ROW LEVEL SECURITY');
        } catch (\Throwable $e) {
            \Log::info('RLS already enabled or skipped on vector_embeddings: ' . $e->getMessage());
        }

        // Drop existing policy kalau ada — idempotent re-run guard.
        DB::statement('DROP POLICY IF EXISTS vector_embeddings_tenant_isolation ON vector_embeddings');

        // Single policy FOR ALL (SELECT/INSERT/UPDATE/DELETE) dengan
        // USING (visibility) + WITH CHECK (write-time integrity).
        // Tanpa WITH CHECK, INSERT/UPDATE bisa nulis row dengan org_id
        // berbeda dari session — kita tutup juga jalur tsb.
        DB::statement(<<<'SQL'
            CREATE POLICY vector_embeddings_tenant_isolation ON vector_embeddings
                FOR ALL
                USING (
                    org_id::text = current_setting('app.current_org_id', true)
                    OR current_setting('app.bypass_rls', true) = 'true'
                )
                WITH CHECK (
                    org_id::text = current_setting('app.current_org_id', true)
                    OR current_setting('app.bypass_rls', true) = 'true'
                )
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('vector_embeddings')) {
            return;
        }

        // Drop policy dulu, baru disable RLS — urutan ini aman walaupun
        // policy sudah tidak ada (IF EXISTS guard).
        DB::statement('DROP POLICY IF EXISTS vector_embeddings_tenant_isolation ON vector_embeddings');

        try {
            DB::statement('ALTER TABLE vector_embeddings DISABLE ROW LEVEL SECURITY');
        } catch (\Throwable $e) {
            \Log::info('RLS already disabled or skipped on vector_embeddings: ' . $e->getMessage());
        }
    }
};
