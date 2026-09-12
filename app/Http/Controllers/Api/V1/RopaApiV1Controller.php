<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ropa;
use App\Models\Vendor;
use App\Services\ModuleWrite\ModuleWriteContext;
use App\Services\ModuleWrite\RopaDpiaWriter;
use Illuminate\Http\Request;

/**
 * API publik v1 — RoPA (baca).
 *
 * Dipakai tenant untuk menarik daftar kegiatan pemrosesannya ke sistem lain:
 * GRC, papan pantau internal, atau laporan berkala. Autentikasi lewat
 * `X-Api-Key` (AuthenticatePartnerApi) yang sudah membawa org, izin, batas
 * laju, dan pencatatan permintaannya sendiri.
 *
 * Jalur tulis memakai RopaDpiaWriter — service yang sama persis dengan yang
 * dipakai antarmuka. Membuat RoPA bukan sekadar INSERT: sekali tulis membawa
 * penomoran berbasis kode divisi, `applyRopaAutoRisk`, percobaan ulang saat kode
 * bentrok, sinkronisasi pivot pihak ketiga dan sistem informasi, jejak audit,
 * notifikasi, DPIA draf otomatis saat risiko tinggi, serta LIA draf otomatis.
 * Menyalin logika itu ke sini akan mengulang sebab temuan F-03, jadi yang
 * dilakukan adalah memakai ulang, bukan menulis ulang.
 *
 * Pelakunya dibawa sebagai ModuleWriteContext tanpa pengguna: pada kunci API
 * memang tidak ada yang login, dan jejak auditnya jujur mencatat itu.
 *
 * `wizard_data` tidak ikut dikirim secara bawaan: isinya besar dan memuat
 * rincian internal (mis. kontak DPO). Minta dengan `?include=wizard_data`.
 */
class RopaApiV1Controller extends Controller
{
    /** Kolom ringkas untuk daftar — sengaja tanpa wizard_data. */
    private const LIST_COLUMNS = [
        'id', 'registration_number', 'processing_activity', 'entity', 'division',
        'work_unit', 'purpose', 'legal_basis', 'risk_level', 'status',
        'retention_period', 'progress', 'created_at', 'updated_at',
    ];

    private function orgId(Request $request): string
    {
        return $request->attributes->get('api_org_id');
    }

    /** GET /api/v1/ropa */
    public function index(Request $request)
    {
        $query = Ropa::where('org_id', $this->orgId($request))
            ->select(self::LIST_COLUMNS);

        if ($request->search) {
            $cari = $request->search;
            $query->where(function ($w) use ($cari) {
                $w->where('processing_activity', 'like', "%{$cari}%")
                    ->orWhere('registration_number', 'like', "%{$cari}%")
                    ->orWhere('purpose', 'like', "%{$cari}%");
            });
        }
        if ($request->risk_level) {
            $query->where('risk_level', $request->risk_level);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->division) {
            $query->where('division', $request->division);
        }
        if ($request->legal_basis) {
            $query->where('legal_basis', $request->legal_basis);
        }
        if ($request->since) {
            $query->where('updated_at', '>=', $request->since);
        }

        // Hanya kolom yang memang ada di daftar yang boleh jadi kunci urut —
        // nilai bebas dari klien akan jadi SQL yang tidak sah.
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

    /** GET /api/v1/ropa/{id} */
    public function show(string $id, Request $request)
    {
        $ropa = Ropa::where('org_id', $this->orgId($request))
            ->with([
                'vendors:id,name,country,risk_level',
                'dpias:id,ropa_id,registration_number,status,risk_level',
            ])
            ->findOrFail($id);

        $sertakanWizard = in_array('wizard_data', explode(',', (string) $request->include), true);
        if (! $sertakanWizard) {
            $ropa->makeHidden('wizard_data');
        }

        $data = $ropa->toArray();
        // Peran menurut UU PDP disimpan di pivot, bukan di baris pihak ketiga:
        // satu pihak ketiga bisa jadi prosesor di satu kegiatan dan pengendali
        // bersama di kegiatan lain.
        $data['third_parties'] = $ropa->vendors->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'country' => $v->country,
            'risk_level' => $v->risk_level,
            'role' => $v->pivot->role,
            'role_label' => Vendor::roleLabel($v->pivot->role),
            'purpose' => $v->pivot->purpose,
        ])->values();
        unset($data['vendors']);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/ropa
     *
     * Payload diteruskan apa adanya ke service, sama seperti jalur antarmuka —
     * `wizard_data` dan kolom turunannya harus bisa lewat utuh. Kolom yang tidak
     * boleh ditentukan pemanggil (`org_id`, `created_by`) ditimpa service dari
     * konteks kunci API, jadi mengirimnya tidak berefek apa-apa.
     */
    public function store(Request $request)
    {
        $request->validate([
            'processing_activity' => 'required|string|max:500',
            'purpose' => 'nullable|string',
            'legal_basis' => 'nullable|string|max:255',
            'risk_level' => 'nullable|in:low,medium,high',
            'status' => 'nullable|string|max:50',
            'wizard_data' => 'nullable|array',
        ]);

        $hasil = app(RopaDpiaWriter::class)->create(
            'ropa',
            $request->all(),
            ModuleWriteContext::forApiKey($this->orgId($request)),
        );

        return response()->json([
            'message' => 'RoPA dibuat.',
            'data' => $hasil['record'],
            // Dua pemicu otomatis yang mungkin ikut lahir; disebut tegas supaya
            // sistem pemanggil tahu ada record lain yang terbentuk atas namanya.
            'auto_dpia_id' => $hasil['auto_dpia_id'],
            'auto_lia_id' => $hasil['auto_lia_id'],
        ], 201);
    }

    /** GET /api/v1/ropa/stats */
    public function stats(Request $request)
    {
        $orgId = $this->orgId($request);
        $dasar = fn () => Ropa::where('org_id', $orgId);

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
