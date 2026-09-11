<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectionMapScan;
use App\Models\Organization;
use App\Services\ConnectionMap\ConnectionMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Peta Koneksi seluruh modul — bagian dari Data Security Posture Management.
 *
 *   POST /security/connection-map/scan        jalankan scanner, simpan hasil
 *   GET  /security/connection-map             hasil scan TERAKHIR + grafnya
 *   GET  /security/connection-map/scans       riwayat scan (tanpa graf)
 *   GET  /security/connection-map/scans/{id}  hasil scan tertentu + grafnya
 *
 * Halaman membaca hasil TERSIMPAN, bukan menghitung ulang tiap dibuka — sama
 * seperti posture snapshot: peta hanya berubah saat scan dijalankan, sehingga
 * setiap tampilan dapat dirujuk ke satu scan yang jelas waktu dan lokasinya.
 */
class ConnectionMapController extends Controller
{
    public function __construct(private ConnectionMapService $maps) {}

    public function latest(Request $request): JsonResponse
    {
        $org = $this->org($request);
        if (! $org) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $scan = ConnectionMapScan::query()
            ->where('org_id', $org->id)
            ->with('scanner:id,name')
            ->latest('scanned_at')
            ->first();

        if (! $scan) {
            return response()->json(['scan' => null, 'graph' => null, 'error' => null]);
        }

        return response()->json($this->present($scan, $org));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $org = $this->org($request);
        if (! $org) {
            return response()->json(['message' => 'Organization context required'], 400);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $scan = ConnectionMapScan::query()
            ->where('org_id', $org->id)
            ->with('scanner:id,name')
            ->findOrFail($id);

        return response()->json($this->present($scan, $org));
    }

    public function history(Request $request): JsonResponse
    {
        $org = $this->org($request);
        if (! $org) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        // Tanpa kolom payload — riwayat hanya butuh ringkasan.
        $scans = ConnectionMapScan::query()
            ->where('org_id', $org->id)
            ->with('scanner:id,name')
            ->latest('scanned_at')
            ->limit(20)
            ->get(['id', 'org_id', 'storage_location', 'storage_driver', 'storage_note',
                'node_count', 'edge_count', 'payload_bytes', 'scanned_by', 'scanned_at']);

        return response()->json(['data' => $scans->map(fn (ConnectionMapScan $s) => $this->meta($s))->values()]);
    }

    public function scan(Request $request): JsonResponse
    {
        $org = $this->org($request);
        if (! $org) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        ['scan' => $scan, 'graph' => $graph] = $this->maps->scanAndStore($org, $request->user()->id);
        $scan->load('scanner:id,name');

        return response()->json([
            'message' => 'Scan selesai.',
            'scan' => $this->meta($scan),
            'graph' => $graph,
            'error' => null,
        ], 201);
    }

    private function org(Request $request): ?Organization
    {
        $orgId = $request->user()?->org_id;

        return $orgId ? Organization::find($orgId) : null;
    }

    /** @return array<string, mixed> */
    private function present(ConnectionMapScan $scan, Organization $org): array
    {
        ['graph' => $graph, 'error' => $error] = $this->maps->loadGraph($scan, $org);

        return ['scan' => $this->meta($scan), 'graph' => $graph, 'error' => $error];
    }

    /** @return array<string, mixed> */
    private function meta(ConnectionMapScan $scan): array
    {
        return [
            'id' => $scan->id,
            'scanned_at' => $scan->scanned_at->toIso8601String(),
            'scanned_by' => $scan->scanner?->name,
            'storage_location' => $scan->storage_location,
            'storage_driver' => $scan->storage_driver,
            'storage_note' => $scan->storage_note,
            'node_count' => $scan->node_count,
            'edge_count' => $scan->edge_count,
            'payload_bytes' => $scan->payload_bytes,
        ];
    }
}
