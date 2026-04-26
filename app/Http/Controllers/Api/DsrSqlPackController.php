<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrRequest;
use App\Services\DsrSqlGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DSR SQL Pack — generate ZIP + download endpoints.
 *
 * Routes:
 *   POST /api/dsr/{id}/sql-pack/generate     — trigger generation, returns metadata
 *   GET  /api/dsr/{id}/sql-pack/download     — stream ZIP file
 *   GET  /api/dsr/{id}/sql-pack/info         — get current pack status (file_count, generated_at)
 */
class DsrSqlPackController extends Controller
{
    public function __construct(private DsrSqlGeneratorService $generator) {}

    /**
     * POST /api/dsr/{id}/sql-pack/generate
     * Generate SQL pack untuk DSR. Idempotent — bisa di-trigger ulang
     * (overwrites previous pack, but execution evidence preserved).
     */
    public function generate(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        // Pre-check: DSR harus verified + ada scope
        if ($dsr->verification_status !== 'verified') {
            return response()->json([
                'error' => 'DSR belum verified. Subject harus verify identity dulu via OTP link sebelum SQL pack bisa di-generate.',
            ], 422);
        }
        if ($dsr->scopes()->count() === 0) {
            return response()->json([
                'error' => 'DSR belum di-assign scope. DPO pilih Information Systems dulu di Scope Picker.',
            ], 422);
        }

        try {
            $result = $this->generator->generatePack($dsr);

            AuditLog::create([
                'org_id' => $user->org_id, 'user_id' => $user->id,
                'module' => 'dsr', 'record_id' => $dsr->id,
                'action' => 'dsr.sql_pack_generate',
                'details' => [
                    'file_count' => $result['file_count'],
                    'total_size' => $result['total_size'],
                ],
            ]);

            // Update DSR status: in_progress → pending_execution kalau scope all baru
            if (in_array($dsr->status, ['pending_review', 'in_progress'], true)) {
                $dsr->update(['status' => 'pending_execution']);
            }

            return response()->json([
                'message' => 'SQL Pack generated. ' . $result['file_count'] . ' files (' . round($result['total_size'] / 1024, 1) . ' KB).',
                'file_count' => $result['file_count'],
                'total_size_bytes' => $result['total_size'],
                'manifest' => $result['manifest'],
                'download_url' => route('dsr.sql_pack.download', ['id' => $dsr->id]),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to generate SQL pack: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/dsr/{id}/sql-pack/download
     * Stream ZIP file. Mark sql_pack_downloaded_at timestamp.
     */
    public function download(Request $request, string $id): StreamedResponse
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $scope = $dsr->scopes()->whereNotNull('sql_pack_url')->first();
        if (!$scope || !$scope->sql_pack_url) {
            abort(404, 'SQL pack belum di-generate. Trigger Generate dulu.');
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($scope->sql_pack_url)) {
            abort(404, 'SQL pack file tidak ditemukan. Re-generate dulu.');
        }

        // Mark all scopes downloaded (single ZIP for whole DSR)
        $dsr->scopes()->update([
            'sql_pack_downloaded_at' => now(),
            'sql_pack_status' => 'downloaded',
        ]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.sql_pack_download',
            'details' => ['ip' => $request->ip()],
        ]);

        $filename = "dsr-{$dsr->request_id}.zip";
        return $disk->download($scope->sql_pack_url, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * GET /api/dsr/{id}/sql-pack/info
     * Current pack status untuk UI dashboard.
     */
    public function info(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->with('scopes')->findOrFail($id);

        $scope = $dsr->scopes->first();
        $exists = false;
        $size = null;
        if ($scope?->sql_pack_url) {
            $disk = Storage::disk('local');
            $exists = $disk->exists($scope->sql_pack_url);
            if ($exists) $size = $disk->size($scope->sql_pack_url);
        }

        return response()->json([
            'has_pack' => $exists,
            'generated_at' => $scope?->sql_pack_generated_at,
            'downloaded_at' => $scope?->sql_pack_downloaded_at,
            'status' => $scope?->sql_pack_status,
            'size_bytes' => $size,
            'scope_count' => $dsr->scopes->count(),
        ]);
    }
}
