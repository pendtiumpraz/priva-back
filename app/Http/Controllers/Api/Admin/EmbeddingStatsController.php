<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\EmbeddingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Admin endpoints untuk monitoring + manajemen vector embeddings (RAG).
 *
 * Capability:
 *   - GET  /api/admin/embeddings/stats   → snapshot counts per source_type
 *   - POST /api/admin/embeddings/reembed → enqueue backfill untuk org caller
 *   - GET  /api/admin/embeddings/health  → ping provider, return latency_ms
 *
 * Multi-tenant scoping:
 *   - Default: stats hanya untuk org_id user yang login.
 *   - Superadmin/root boleh kirim ?all=1 untuk lihat aggregate platform-wide.
 *
 * Permission:
 *   - Role harus salah satu dari: root, superadmin, admin (tenant-level).
 *   - Tidak pakai middleware permission:<module> karena ini cross-cutting
 *     RAG infra, bukan modul domain.
 */
class EmbeddingStatsController extends Controller
{
    public function __construct(private EmbeddingService $embedding) {}

    /**
     * GET /api/admin/embeddings/stats
     *
     * Response:
     *   {
     *     enabled, provider, model, dimension, available,
     *     counts_per_source: { ropa: 123, dpia: 45, ... },
     *     total_embeddings: 168,
     *     latest_embed_at: "2026-05-19T12:34:56Z",
     *     scope: "org" | "platform"
     *   }
     */
    public function stats(Request $request)
    {
        if ($forbidden = $this->guard($request)) {
            return $forbidden;
        }

        $user = $request->user();
        $isPrivileged = in_array($user->role ?? null, ['root', 'superadmin'], true);
        $wantAll = $isPrivileged && (string) $request->query('all', '0') === '1';

        $enabled = (bool) config('ai_embedding.enabled', false);
        $provider = (string) config('ai_embedding.provider', '');

        $model = '';
        $dimension = 0;
        $available = false;
        try {
            $model = $this->embedding->getModelName();
            $dimension = $this->embedding->getDimension();
        } catch (\Throwable $e) {
            Log::warning('[EmbeddingStats] failed to read provider config', ['error' => $e->getMessage()]);
        }
        try {
            $available = $this->embedding->isAvailable();
        } catch (\Throwable $e) {
            $available = false;
        }

        // Tabel mungkin belum ada di env yang belum migrasi RAG (sqlite test, dll).
        $counts = [];
        $total = 0;
        $latest = null;
        $scope = $wantAll ? 'platform' : 'org';

        if (Schema::hasTable('vector_embeddings')) {
            $base = DB::table('vector_embeddings')->whereNull('deleted_at');

            if (! $wantAll) {
                $orgId = $user->org_id ?? null;
                if (! $orgId) {
                    return response()->json([
                        'message' => 'No org context for current user',
                    ], 400);
                }
                $base->where('org_id', $orgId);
            }

            $rows = (clone $base)
                ->select('source_type', DB::raw('COUNT(*) as c'))
                ->groupBy('source_type')
                ->get();

            foreach ($rows as $r) {
                $counts[(string) $r->source_type] = (int) $r->c;
                $total += (int) $r->c;
            }

            $latestRaw = (clone $base)->max('created_at');
            if ($latestRaw) {
                try {
                    $latest = \Illuminate\Support\Carbon::parse($latestRaw)->toIso8601String();
                } catch (\Throwable $e) {
                    $latest = (string) $latestRaw;
                }
            }
        }

        return response()->json([
            'enabled' => $enabled,
            'provider' => $provider,
            'model' => $model,
            'dimension' => $dimension,
            'available' => $available,
            'counts_per_source' => $counts,
            'total_embeddings' => $total,
            'latest_embed_at' => $latest,
            'scope' => $scope,
        ]);
    }

    /**
     * POST /api/admin/embeddings/reembed
     *
     * Body: { "module": "ropa" | "dpia" | "breach" | "vendor" | "kb" | "all" }
     *
     * Enqueue Artisan command embeddings:backfill untuk org caller. Superadmin
     * boleh kirim org_id eksplisit untuk backfill org lain (multi-tenant ops).
     */
    public function reembedAll(Request $request)
    {
        if ($forbidden = $this->guard($request)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'module' => 'required|string|in:ropa,dpia,breach,vendor,kb,all',
            'org_id' => 'nullable|string|uuid',
        ]);

        $user = $request->user();
        $isPrivileged = in_array($user->role ?? null, ['root', 'superadmin'], true);

        // Default ke org user. Privileged role boleh override via body org_id.
        $orgId = $user->org_id ?? null;
        if ($isPrivileged && ! empty($validated['org_id'])) {
            $orgId = $validated['org_id'];
        }

        if (! $orgId) {
            return response()->json([
                'message' => 'org_id is required (either from user context or request body for superadmin)',
            ], 400);
        }

        if (! (bool) config('ai_embedding.enabled', false)) {
            return response()->json([
                'message' => 'AI embedding tidak aktif (config ai_embedding.enabled=false)',
            ], 409);
        }

        try {
            Artisan::queue('embeddings:backfill', [
                'module' => $validated['module'],
                '--org' => $orgId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[EmbeddingStats] failed to queue backfill', [
                'org_id' => $orgId,
                'module' => $validated['module'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal enqueue backfill command',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'queued' => true,
            'module' => $validated['module'],
            'org_id' => $orgId,
        ]);
    }

    /**
     * GET /api/admin/embeddings/health
     *
     * Ping endpoint /health pada embedding provider untuk diagnose latency
     * + reachability tanpa burning credits/quota.
     */
    public function health(Request $request)
    {
        if ($forbidden = $this->guard($request)) {
            return $forbidden;
        }

        $enabled = (bool) config('ai_embedding.enabled', false);
        $provider = (string) config('ai_embedding.provider', '');
        $providerConfig = config('ai_embedding.'.$provider);
        $baseUrl = is_array($providerConfig) ? (string) ($providerConfig['base_url'] ?? '') : '';

        if (! $enabled) {
            return response()->json([
                'status' => 'disabled',
                'provider' => $provider,
                'latency_ms' => null,
                'message' => 'AI embedding disabled in config',
            ]);
        }

        if ($baseUrl === '') {
            return response()->json([
                'status' => 'misconfigured',
                'provider' => $provider,
                'latency_ms' => null,
                'message' => 'base_url not configured for provider',
            ]);
        }

        $start = microtime(true);
        $status = 'down';
        $httpStatus = null;
        $message = null;

        try {
            $response = Http::timeout(5)->get(rtrim($baseUrl, '/').'/health');
            $httpStatus = $response->status();
            if ($response->successful()) {
                $status = 'ok';
            } else {
                $status = 'unhealthy';
                $message = "HTTP {$httpStatus}";
            }
        } catch (\Throwable $e) {
            $status = 'down';
            $message = $e->getMessage();
        }
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return response()->json([
            'status' => $status,
            'provider' => $provider,
            'base_url' => $baseUrl,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'message' => $message,
        ]);
    }

    /**
     * Role guard. Return null kalau OK, atau JsonResponse 403 kalau ditolak.
     */
    private function guard(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $role = $user->role ?? null;
        if (! in_array($role, ['root', 'superadmin', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }
}
