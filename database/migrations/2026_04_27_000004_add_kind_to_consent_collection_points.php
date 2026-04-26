<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split consent collections into 2 distinct kinds:
 *
 *   cookie_banner — untuk VISITOR di landing page / public website.
 *                   Anonymous-only audience, hanya category cookie
 *                   (essential/analytics/marketing/functional).
 *                   Default banner_bottom UI.
 *
 *   app_consent   — untuk USER LOGGED-IN di apps (mobile/web post-login).
 *                   Logged-in audience required (klien provide user_identifier).
 *                   Semua category boleh (incl. third_party, biometric, etc).
 *                   Default modal_center UI.
 *
 * Backfill: existing rows dengan audience='logged_in_only' → app_consent,
 * lainnya → cookie_banner (default safe).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('consent_collection_points')) return;

        if (!Schema::hasColumn('consent_collection_points', 'kind')) {
            try {
                Schema::table('consent_collection_points', function (Blueprint $t) {
                    $t->string('kind', 32)->default('cookie_banner')->after('name');
                });
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'duplicate') && !str_contains($e->getMessage(), 'already')) throw $e;
            }
            try {
                Schema::table('consent_collection_points', function (Blueprint $t) {
                    $t->index(['org_id', 'kind'], 'cp_org_kind_idx');
                });
            } catch (\Throwable $e) {}
        }

        // Backfill — heuristic by existing audience
        try {
            DB::table('consent_collection_points')
                ->where('audience', 'logged_in_only')
                ->update(['kind' => 'app_consent']);
        } catch (\Throwable $e) {
            \Log::warning('Backfill kind=app_consent failed: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('consent_collection_points')) return;
        try { Schema::table('consent_collection_points', fn($t) => $t->dropIndex('cp_org_kind_idx')); } catch (\Throwable $e) {}
        if (Schema::hasColumn('consent_collection_points', 'kind')) {
            try { Schema::table('consent_collection_points', fn($t) => $t->dropColumn('kind')); } catch (\Throwable $e) {}
        }
    }
};
