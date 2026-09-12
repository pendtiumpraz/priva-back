<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformIncident;
use App\Services\PlatformIncidentFanoutService;
use App\Services\RegistrationCodeService;
use Illuminate\Http\Request;

/**
 * Daftar insiden PLATFORM — root/platform staff saja.
 *
 * Seluruh modul Breach mencatat insiden yang dialami TENANT. Tidak ada tempat
 * untuk mencatat insiden yang dialami platform ini sendiri, padahal terhadap
 * tenant kami berkedudukan sebagai Prosesor: satu insiden di sisi kami menjadi
 * kewajiban pemberitahuan bagi setiap Pengendali yang datanya kami proses
 * (UU PDP Pasal 46 — paling lambat 3x24 jam).
 *
 * Pencatatan dan penyebaran SENGAJA dipisah menjadi dua langkah, sama seperti
 * persetujuan dan pemusnahan pada retensi RoPA: penyebaran menyentuh seluruh
 * tenant sekaligus dan tidak bisa ditarik kembali, jadi ia tidak boleh terjadi
 * sebagai efek samping dari menekan "simpan".
 */
class PlatformIncidentController extends Controller
{
    public function __construct(
        private PlatformIncidentFanoutService $fanout,
        private RegistrationCodeService $codes,
    ) {}

    public function index(Request $request)
    {
        $q = PlatformIncident::query()->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function show(string $id)
    {
        return response()->json(['data' => PlatformIncident::findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'severity' => 'required|in:low,medium,high,critical',
            'detected_at' => 'nullable|date',
            'affected_scope' => 'required|in:all,selected',
            'affected_org_ids' => 'required_if:affected_scope,selected|array',
            'affected_org_ids.*' => 'string',
            'remediation' => 'nullable|string|max:10000',
        ]);

        $regen = fn () => $this->codes->nextGlobal('PLT', PlatformIncident::class, 'incident_code');

        $incident = $this->codes->createWithRetry(new PlatformIncident, array_merge($data, [
            'incident_code' => $regen(),
            'status' => PlatformIncident::STATUS_DRAFT,
            'detected_at' => $data['detected_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]), 'incident_code', $regen);

        $this->log($request, $incident, 'created', 'Insiden platform dicatat');

        return response()->json(['data' => $incident], 201);
    }

    /** Perubahan hanya selama belum disebar — sesudahnya tenant sudah menerima isinya. */
    public function update(Request $request, string $id)
    {
        $incident = PlatformIncident::findOrFail($id);

        if ($incident->sudahDisebar()) {
            return response()->json([
                'message' => 'Insiden sudah disebarkan ke tenant dan tidak dapat diubah lagi.',
            ], 422);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:10000',
            'severity' => 'sometimes|in:low,medium,high,critical',
            'detected_at' => 'nullable|date',
            'affected_scope' => 'sometimes|in:all,selected',
            'affected_org_ids' => 'nullable|array',
            'remediation' => 'nullable|string|max:10000',
        ]);

        $incident->fill($data)->save();
        $this->log($request, $incident, 'updated', 'Insiden platform diperbarui');

        return response()->json(['data' => $incident->fresh()]);
    }

    /**
     * Sebarkan ke tenant terdampak — membuat catatan breach di sisi mereka.
     *
     * Ditolak kalau sudah pernah disebar: mengulang akan menggandakan catatan
     * insiden di setiap tenant, dan catatan ganda pada register insiden justru
     * merusak nilainya sebagai bukti.
     */
    public function sebarkan(Request $request, string $id)
    {
        $incident = PlatformIncident::findOrFail($id);

        if ($incident->sudahDisebar()) {
            return response()->json([
                'message' => 'Insiden ini sudah pernah disebarkan.',
                'fanned_out_at' => $incident->fanned_out_at,
            ], 422);
        }

        $hasil = $this->fanout->sebarkan($incident);
        $gagal = collect($hasil)->where('status', 'failed')->count();

        $incident->forceFill([
            'status' => PlatformIncident::STATUS_NOTIFIED,
            'fanned_out_at' => now(),
            'fanout_results' => $hasil,
        ])->save();

        $this->log($request, $incident, 'fanned_out',
            'Disebarkan ke '.count($hasil).' tenant ('.$gagal.' gagal)');

        return response()->json([
            'message' => 'Insiden disebarkan ke '.count($hasil).' tenant.',
            'ringkasan' => [
                'total' => count($hasil),
                'berhasil' => count($hasil) - $gagal,
                'gagal' => $gagal,
            ],
            'hasil' => $hasil,
            'data' => $incident->fresh(),
        ]);
    }

    public function tutup(Request $request, string $id)
    {
        $incident = PlatformIncident::findOrFail($id);

        $incident->forceFill([
            'status' => PlatformIncident::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->log($request, $incident, 'closed', 'Insiden platform ditutup');

        return response()->json(['data' => $incident->fresh()]);
    }

    private function log(Request $request, PlatformIncident $incident, string $action, string $detail): void
    {
        try {
            AuditLog::create([
                'module' => 'platform_incident',
                'record_id' => $incident->id,
                'action' => $action,
                'user_name' => $request->user()->name ?? 'Unknown',
                'user_role' => $request->user()->role ?? 'user',
                'section' => 'insiden_platform',
                'changes' => [
                    'incident_code' => $incident->incident_code,
                    'severity' => $incident->severity,
                    'detail' => $detail,
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log insiden platform gagal: '.$e->getMessage());
        }
    }
}
