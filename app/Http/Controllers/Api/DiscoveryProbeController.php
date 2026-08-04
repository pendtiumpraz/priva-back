<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryProbeConfig;
use App\Models\InformationSystem;
use App\Models\SystemSetting;
use App\Services\ActiveDiscoveryService;
use App\Services\PassiveDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penemuan data store yang belum terdaftar.
 *
 * Dua mode dengan risiko yang sangat berbeda, dan perbedaan itu tercermin di
 * setiap endpoint: mode pasif dapat langsung dijalankan siapa pun yang
 * berwenang, mode aktif harus melewati saklar platform, persetujuan tenant,
 * dan penetapan rentang IP.
 */
class DiscoveryProbeController extends Controller
{
    public function __construct(
        private readonly PassiveDiscoveryService $passive,
        private readonly ActiveDiscoveryService $active,
    ) {}

    /** Konfigurasi tenant beserta keadaan saklar platform. */
    public function config(Request $request): JsonResponse
    {
        $config = $this->configFor($request);
        $gate = $this->active->gate($config);

        return response()->json([
            'data' => $config,
            'meta' => [
                'modes' => DiscoveryProbeConfig::MODES,
                'default_ports' => DiscoveryProbeConfig::DEFAULT_PORTS,
                // Keadaan saklar platform ditampilkan apa adanya supaya tenant
                // memahami mengapa mode aktif tidak dapat dinyalakan, alih-alih
                // menduga konfigurasinya yang salah.
                'platform' => [
                    'active_scan_allowed' => (bool) SystemSetting::get('discovery.active_scan_allowed', false),
                    'max_hosts' => (int) SystemSetting::get('discovery.active_scan_max_hosts', 1024),
                    'default_mode' => SystemSetting::get('discovery.default_mode', DiscoveryProbeConfig::MODE_PASSIVE),
                ],
                'gate' => $gate,
            ],
        ]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'sometimes|in:'.implode(',', DiscoveryProbeConfig::MODES),
            'cidr_ranges' => 'sometimes|nullable|array|max:50',
            'cidr_ranges.*' => 'string|max:64',
            'ports' => 'sometimes|nullable|array|max:30',
            'ports.*' => 'integer|min:1|max:65535',
            'is_enabled' => 'sometimes|boolean',
            'approve_active_scan' => 'sometimes|boolean',
        ]);

        $config = $this->configFor($request);
        $approve = $data['approve_active_scan'] ?? null;
        unset($data['approve_active_scan']);

        // Persetujuan pemindaian aktif dicatat beserta siapa dan kapan, dan
        // GUGUR setiap kali rentang IP berubah. Persetujuan atas satu rentang
        // bukan persetujuan atas rentang lain.
        $rangesChanged = array_key_exists('cidr_ranges', $data)
            && ($data['cidr_ranges'] ?? []) !== ($config->cidr_ranges ?? []);

        if ($approve === true) {
            $data['active_scan_approved_by'] = $request->user()->id;
            $data['active_scan_approved_at'] = now();
        } elseif ($approve === false || $rangesChanged) {
            $data['active_scan_approved_by'] = null;
            $data['active_scan_approved_at'] = null;
        }

        $config->update($data);

        AuditLog::log('data-discovery', $config->id, 'discovery_config_updated', [
            'mode' => $config->mode,
            'ranges' => $config->cidr_ranges,
            'approved' => (bool) $config->active_scan_approved_at,
            'approval_revoked_by_range_change' => $rangesChanged && $approve !== true,
        ], 'discovery');

        return response()->json([
            'data' => $config->fresh(),
            'meta' => ['gate' => $this->active->gate($config->fresh())],
        ]);
    }

    /** Cerna jejak yang sudah ada — mode pasif. */
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|in:config_file,connection_log,cmdb',
            'text' => 'required_without:rows|nullable|string|max:2000000',
            'rows' => 'required_without:text|nullable|array|max:5000',
            'label' => 'nullable|string|max:150',
        ]);

        $orgId = $request->user()->org_id;

        $result = $data['source'] === 'cmdb' && ! empty($data['rows'])
            ? $this->passive->ingestCmdb($orgId, $data['rows'])
            : $this->passive->ingestText($orgId, (string) ($data['text'] ?? ''), $data['source'], $data['label'] ?? null);

        $this->configFor($request)->update(['last_run_at' => now()]);

        AuditLog::log('data-discovery', $orgId, 'passive_discovery_ingested', array_merge(
            $result,
            ['source' => $data['source']],
        ), 'discovery');

        return response()->json([
            'message' => "{$result['found']} endpoint terbaca — {$result['new']} belum terdaftar, {$result['known']} sudah terdaftar.",
            'data' => $result,
        ]);
    }

    /** Jalankan pemindaian jaringan — mode aktif. */
    public function scan(Request $request): JsonResponse
    {
        $config = $this->configFor($request);
        $gate = $this->active->gate($config);

        if (! $gate['allowed']) {
            return response()->json([
                'message' => $gate['reason'],
                'gate' => $gate['gate'],
            ], 422);
        }

        $result = $this->active->run($config, $request->user()->id);

        return response()->json([
            'message' => "{$result['scanned_hosts']} host dipindai, {$result['open']} porta terbuka, "
                ."{$result['new']} belum terdaftar.",
            'data' => $result,
        ]);
    }

    /** Daftar kandidat beserta ringkasannya. */
    public function candidates(Request $request): JsonResponse
    {
        $query = DiscoveryCandidate::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('host', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
                ->orderByDesc('last_seen_at')
                ->paginate((int) $request->input('per_page', 50)),
            'meta' => [
                'new' => DiscoveryCandidate::where('status', 'new')->count(),
                'registered' => DiscoveryCandidate::where('status', 'registered')->count(),
                'ignored' => DiscoveryCandidate::where('status', 'ignored')->count(),
                'sources' => DiscoveryCandidate::SOURCES,
            ],
        ]);
    }

    /** Tandai kandidat sudah ditinjau, atau daftarkan sebagai sistem informasi. */
    public function updateCandidate(Request $request, string $id): JsonResponse
    {
        $candidate = DiscoveryCandidate::findOrFail($id);

        $data = $request->validate([
            'status' => 'sometimes|in:'.implode(',', DiscoveryCandidate::STATUSES),
            'note' => 'sometimes|nullable|string|max:1000',
            'register_as_system' => 'sometimes|boolean',
            'system_name' => 'nullable|string|max:200',
        ]);

        if ($data['register_as_system'] ?? false) {
            $system = InformationSystem::create([
                'org_id' => $candidate->org_id,
                'name' => $data['system_name'] ?: ($candidate->name ?: $candidate->host),
                'source_type' => $candidate->service_hint ?: 'unknown',
                'description' => 'Didaftarkan dari hasil penemuan ('.$candidate->source.').',
                'connection_config' => ['host' => $candidate->host, 'port' => $candidate->port],
                'created_by' => $request->user()->id,
            ]);

            $candidate->update([
                'status' => 'registered',
                'matched_system_id' => $system->id,
                'note' => $data['note'] ?? $candidate->note,
            ]);

            AuditLog::log('data-discovery', $system->id, 'registered_from_discovery', [
                'host' => $candidate->host,
                'port' => $candidate->port,
                'source' => $candidate->source,
            ], 'discovery');

            return response()->json([
                'message' => 'Sistem informasi dibuat dari kandidat.',
                'data' => ['candidate' => $candidate->fresh(), 'information_system_id' => $system->id],
            ], 201);
        }

        $candidate->update(array_intersect_key($data, array_flip(['status', 'note'])));

        return response()->json(['data' => $candidate->fresh()]);
    }

    /**
     * Konfigurasi tenant, dibuat saat pertama diakses.
     *
     * Mode awalnya mengikuti setelan platform, dan setelan itu sendiri
     * berbawaan pasif — sehingga tenant baru tidak pernah lahir dengan
     * kemampuan memindai jaringan.
     */
    private function configFor(Request $request): DiscoveryProbeConfig
    {
        return DiscoveryProbeConfig::firstOrCreate(
            ['org_id' => $request->user()->org_id],
            [
                'mode' => SystemSetting::get('discovery.default_mode', DiscoveryProbeConfig::MODE_PASSIVE),
                'is_enabled' => true,
                'created_by' => $request->user()->id,
            ],
        );
    }
}
