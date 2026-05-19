<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FORCE Row Level Security pada vector_embeddings.
 *
 * Default Postgres: table OWNER bypass RLS policy. Karena Laravel
 * connection ke Neon biasanya as `neondb_owner` (which is the table owner),
 * RLS policy yang sudah dibuat di 120002 tidak ter-enforce untuk app
 * connection — kebocoran security.
 *
 * Fix: `ALTER TABLE ... FORCE ROW LEVEL SECURITY` paksa owner juga kena
 * policy. Setelah ini:
 *   - SET app.current_org_id = X → SELECT/INSERT/UPDATE cuma row org X
 *   - SET app.bypass_rls = 'true' → bypass total (untuk admin operations,
 *     backfill, queue worker dengan multi-tenant batch)
 *   - Tanpa SET → SELECT return 0 rows, INSERT/UPDATE reject (fail closed)
 *
 * Reference: Postgres docs "Row Security Policies" — section
 * "When a Policy Is Applied":
 *   https://www.postgresql.org/docs/current/ddl-rowsecurity.html
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        if (!Schema::hasTable('vector_embeddings')) return;

        try {
            DB::statement('ALTER TABLE vector_embeddings FORCE ROW LEVEL SECURITY');
        } catch (\Throwable $e) {
            \Log::warning('FORCE RLS migration: failed or already set', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        if (!Schema::hasTable('vector_embeddings')) return;

        try {
            DB::statement('ALTER TABLE vector_embeddings NO FORCE ROW LEVEL SECURITY');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
