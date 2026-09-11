<?php

namespace App\Services\ConnectionMap;

use App\Models\AuditLog;
use App\Models\ConnectionMapScan;
use App\Models\Organization;
use App\Services\TenantStorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Menjalankan ConnectionMapScanner lalu MENYIMPAN hasilnya:
 *
 *   - Organisasi memasang storage eksternal (BYOS, pool, atau default
 *     platform — lapisan yang sama dengan TenantStorageService::getDisk)
 *     → graf ditulis sebagai file JSON di `tenants/{org}/connection-maps/`;
 *     baris DB hanya menyimpan lokasi + sidik jari SHA-256-nya.
 *   - Tidak ada storage eksternal → graf disimpan di backend (kolom payload).
 *   - Storage terpasang tetapi gagal ditulis → tetap disimpan di backend,
 *     dengan catatan alasannya. Hasil scan tidak boleh hilang hanya karena
 *     bucket sedang bermasalah.
 */
class ConnectionMapService
{
    public function __construct(
        private ConnectionMapScanner $scanner,
        private TenantStorageService $storage,
    ) {}

    /**
     * @return array{scan: ConnectionMapScan, graph: array<string, mixed>}
     */
    public function scanAndStore(Organization $org, ?string $userId): array
    {
        $graph = $this->scanner->scan($org);
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $scan = new ConnectionMapScan([
            'org_id' => $org->id,
            'payload_sha256' => hash('sha256', $json),
            'payload_bytes' => strlen($json),
            'node_count' => $graph['stats']['nodes'],
            'edge_count' => $graph['stats']['edges'],
            'summary' => [
                'modules' => $graph['modules'],
                'insights' => array_map(fn ($i) => ['key' => $i['key'], 'count' => $i['count']], $graph['insights']),
            ],
            'scanned_by' => $userId,
            'scanned_at' => now(),
        ]);
        // Id ditetapkan di muka karena nama file di storage memakainya.
        $scan->id = (string) Str::uuid();

        $driver = $this->storage->externalDriver($org);
        if ($driver === null) {
            $scan->fill(['storage_location' => ConnectionMapScan::LOCATION_DATABASE, 'payload' => $graph]);
        } else {
            $path = $this->pathFor($org, $scan->id);
            try {
                if ($this->storage->getDisk($org)->put($path, $json) === false) {
                    throw new RuntimeException('disk menolak penulisan');
                }
                $scan->fill([
                    'storage_location' => ConnectionMapScan::LOCATION_STORAGE,
                    'storage_driver' => $driver,
                    'storage_path' => $path,
                ]);
            } catch (Throwable $e) {
                Log::warning("ConnectionMap: gagal menulis hasil scan ke storage {$driver} (org {$org->id}): ".$e->getMessage());
                $scan->fill([
                    'storage_location' => ConnectionMapScan::LOCATION_DATABASE,
                    'storage_note' => "Storage {$driver} tidak dapat ditulis saat scan; hasil disimpan di backend.",
                    'payload' => $graph,
                ]);
            }
        }

        $scan->save();

        try {
            AuditLog::log('security', $scan->id, 'connection_map_scan', [
                'nodes' => $scan->node_count,
                'edges' => $scan->edge_count,
                'storage_location' => $scan->storage_location,
                'storage_driver' => $scan->storage_driver,
            ], 'connection_map');
        } catch (Throwable $e) {
            // Audit gagal tidak boleh membatalkan scan yang sudah tersimpan.
            Log::warning('ConnectionMap: audit log gagal: '.$e->getMessage());
        }

        return ['scan' => $scan, 'graph' => $graph];
    }

    /**
     * Graf milik sebuah scan. `graph` null (dengan `error` berisi alasannya)
     * bila file di storage tidak lagi dapat dibaca — storage diganti/dihapus —
     * atau isinya tidak cocok dengan sidik jari yang tercatat saat scan.
     *
     * @return array{graph: ?array<string, mixed>, error: ?string}
     */
    public function loadGraph(ConnectionMapScan $scan, Organization $org): array
    {
        if ($scan->storage_location !== ConnectionMapScan::LOCATION_STORAGE) {
            return ['graph' => $scan->payload, 'error' => null];
        }

        $path = (string) $scan->storage_path;
        // Baris yang menunjuk ke luar folder tenant ini tidak pernah dibaca.
        if (! str_starts_with($path, "tenants/{$org->id}/connection-maps/")) {
            return ['graph' => null, 'error' => 'Lokasi file hasil scan tidak valid. Jalankan scan ulang.'];
        }

        try {
            $raw = $this->storage->getTenantFileContents($org, $path);
        } catch (Throwable $e) {
            Log::warning("ConnectionMap: gagal membaca {$path} (org {$org->id}): ".$e->getMessage());
            $raw = null;
        }
        if ($raw === null) {
            return ['graph' => null, 'error' => 'File hasil scan tidak dapat dibaca dari storage. Jalankan scan ulang.'];
        }
        if ($scan->payload_sha256 && ! hash_equals($scan->payload_sha256, hash('sha256', $raw))) {
            return ['graph' => null, 'error' => 'File hasil scan di storage berubah sejak scan dijalankan (sidik jari tidak cocok). Jalankan scan ulang.'];
        }

        $graph = json_decode($raw, true);

        return is_array($graph)
            ? ['graph' => $graph, 'error' => null]
            : ['graph' => null, 'error' => 'File hasil scan rusak. Jalankan scan ulang.'];
    }

    public function pathFor(Organization $org, string $scanId): string
    {
        return "tenants/{$org->id}/connection-maps/{$scanId}.json";
    }
}
