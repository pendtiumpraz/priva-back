<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dpia;
use App\Services\ModuleWrite\ModuleWriteContext;
use App\Services\ModuleWrite\RopaDpiaWriter;
use Illuminate\Http\Request;

/**
 * API publik v1 — DPIA (baca).
 *
 * Pasangan dari RopaApiV1Controller: menarik hasil penilaian dampak ke sistem
 * GRC atau papan pantau tenant. Autentikasi lewat `X-Api-Key`
 * (AuthenticatePartnerApi) yang membawa org, izin, batas laju, dan pencatatan
 * permintaannya sendiri.
 *
 * SENGAJA HANYA BACA, dengan alasan yang sama seperti RoPA: pembuatan DPIA
 * lewat ModuleCrudController menjalankan penomoran `DPIA-YYYY-NNN` yang
 * dihitung lintas tenant (kendala uniknya global, lihat temuan F-03) berikut
 * percobaan ulang saat bentrok, dan bergantung pada `$request->user()` yang
 * tidak ada pada permintaan berkunci API. Selain itu DPIA kerap lahir otomatis
 * dari RoPA berisiko tinggi — jalur tulis lewat API perlu diselaraskan dengan
 * pemicu itu lebih dulu supaya tidak muncul DPIA kembar untuk satu RoPA.
 *
 * `wizard_data` tidak ikut dikirim secara bawaan (besar dan berisi rincian
 * kerja internal). Minta dengan `?include=wizard_data`.
 */
class DpiaApiV1Controller extends Controller
{
    /** Kolom ringkas untuk daftar — sengaja tanpa wizard_data. */
    private const LIST_COLUMNS = [
        'id', 'registration_number', 'ropa_id', 'risk_level', 'status',
        'description', 'progress', 'approved_at', 'created_at', 'updated_at',
    ];

    private function orgId(Request $request): string
    {
        return $request->attributes->get('api_org_id');
    }

    /** GET /api/v1/dpia */
    public function index(Request $request)
    {
        $query = Dpia::where('org_id', $this->orgId($request))
            ->select(self::LIST_COLUMNS);

        if ($request->search) {
            $cari = $request->search;
            $query->where(function ($w) use ($cari) {
                $w->where('registration_number', 'like', "%{$cari}%")
                    ->orWhere('description', 'like', "%{$cari}%");
            });
        }
        if ($request->risk_level) {
            $query->where('risk_level', $request->risk_level);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->ropa_id) {
            $query->where('ropa_id', $request->ropa_id);
        }
        if ($request->since) {
            $query->where('updated_at', '>=', $request->since);
        }

        // Kunci urut dibatasi pada kolom daftar — nilai bebas dari klien akan
        // jadi SQL yang tidak sah.
        $sort = in_array($request->sort, self::LIST_COLUMNS, true) ? $request->sort : 'created_at';
        $order = strtolower((string) $request->order) === 'asc' ? 'asc' : 'desc';

        $items = $query->orderBy($sort, $order)
            ->paginate(min((int) ($request->per_page ?? 20), 100));

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /** GET /api/v1/dpia/{id} */
    public function show(string $id, Request $request)
    {
        $dpia = Dpia::where('org_id', $this->orgId($request))
            ->with(['ropas:id,registration_number,processing_activity,risk_level'])
            ->findOrFail($id);

        $sertakanWizard = in_array('wizard_data', explode(',', (string) $request->include), true);
        if (! $sertakanWizard) {
            $dpia->makeHidden('wizard_data');
        }

        $data = $dpia->toArray();
        // Satu DPIA bisa menaungi banyak kegiatan pemrosesan sekaligus; kolom
        // `ropa_id` hanya menyimpan induk warisan, sisanya ada di pivot.
        $data['linked_ropas'] = $dpia->ropas->map(fn ($r) => [
            'id' => $r->id,
            'registration_number' => $r->registration_number,
            'processing_activity' => $r->processing_activity,
            'risk_level' => $r->risk_level,
        ])->values();
        unset($data['ropas']);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/dpia
     *
     * Memakai service yang sama dengan jalur antarmuka, termasuk penomoran
     * DPIA-YYYY-NNN yang dihitung lintas tenant dan sinkronisasi pivot
     * `dpia_ropa` dari wizard.
     */
    public function store(Request $request)
    {
        $request->validate([
            'description' => 'nullable|string',
            'ropa_id' => 'nullable|uuid',
            'risk_level' => 'nullable|in:low,medium,high',
            'status' => 'nullable|string|max:50',
            'wizard_data' => 'nullable|array',
        ]);

        $hasil = app(RopaDpiaWriter::class)->create(
            'dpia',
            $request->all(),
            ModuleWriteContext::forApiKey($this->orgId($request)),
        );

        return response()->json([
            'message' => 'DPIA dibuat.',
            'data' => $hasil['record'],
        ], 201);
    }

    /** GET /api/v1/dpia/stats */
    public function stats(Request $request)
    {
        $orgId = $this->orgId($request);
        $dasar = fn () => Dpia::where('org_id', $orgId);

        return response()->json([
            'data' => [
                'total' => $dasar()->count(),
                'by_risk_level' => $dasar()->selectRaw('risk_level, count(*) as jumlah')
                    ->groupBy('risk_level')->pluck('jumlah', 'risk_level'),
                'by_status' => $dasar()->selectRaw('status, count(*) as jumlah')
                    ->groupBy('status')->pluck('jumlah', 'status'),
                'high_risk' => $dasar()->where('risk_level', 'high')->count(),
            ],
        ]);
    }
}
