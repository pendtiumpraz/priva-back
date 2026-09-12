<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorRopa;
use App\Models\VendorRopaEditRequest;
use App\Services\VendorRopaTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman publik "RoPA Pihak Ketiga" — pihak ketiga mengisi catatan pemrosesan
 * miliknya sendiri tanpa akun, lewat tautan sekali-kirim dari pengendali.
 *
 * Middleware PublicVendorRopaTokenMiddleware sudah: resolve token, menolak
 * tautan kedaluwarsa, mengunci penulisan setelah dikirim (kecuali permintaan
 * akses ubah), menyetel tenant context, dan melampirkan record sebagai
 * `$request->_vendorRopa`. Controller ini fokus pada isinya saja.
 */
class RopaPihakKetigaPublikController extends Controller
{
    /** Aturan isi RoPA pihak ketiga; dipakai simpan draf maupun kirim. */
    private function contentRules(bool $forSubmit): array
    {
        $req = $forSubmit ? 'required' : 'nullable';

        return [
            'processing_activity' => "{$req}|string|max:255",
            'purpose' => "{$req}|string|max:2000",
            'legal_basis' => 'nullable|string|max:255',
            'role' => 'nullable|in:'.implode(',', Vendor::ROLES),
            'data_categories' => 'nullable|array|max:100',
            'data_categories.*' => 'string|max:200',
            'data_subjects' => 'nullable|array|max:100',
            'data_subjects.*' => 'string|max:200',
            'retention_period' => 'nullable|string|max:255',
            'storage_locations' => 'nullable|array|max:100',
            'storage_locations.*' => 'string|max:200',
            'cross_border' => 'nullable|boolean',
            'cross_border_countries' => 'nullable|array|max:100',
            'cross_border_countries.*' => 'string|max:100',
            'sub_processors' => 'nullable|array|max:100',
            'sub_processors.*.name' => 'required_with:sub_processors|string|max:200',
            'sub_processors.*.country' => 'nullable|string|max:100',
            'sub_processors.*.purpose' => 'nullable|string|max:500',
            'security_measures' => 'nullable|array|max:100',
            'security_measures.*' => 'string|max:200',
            'notes' => 'nullable|string|max:2000',
            'pic_name' => 'nullable|string|max:200',
            'pic_email' => 'nullable|email|max:200',
            'pic_phone' => 'nullable|string|max:50',
        ];
    }

    /** Bentuk tampilan untuk halaman publik — tanpa kolom internal pengendali. */
    private function present(VendorRopa $vendorRopa): array
    {
        $vendor = Vendor::find($vendorRopa->vendor_id);
        $org = Organization::find($vendorRopa->org_id);

        return [
            'organisasi' => ['nama' => $org?->name],
            'pihak_ketiga' => ['nama' => $vendor?->name],
            'status' => $vendorRopa->status,
            'is_locked' => $vendorRopa->isLocked(),
            'submitted_at' => optional($vendorRopa->submitted_at)->toIso8601String(),
            'review_notes' => $vendorRopa->review_notes,
            'isi' => [
                'processing_activity' => $vendorRopa->processing_activity,
                'purpose' => $vendorRopa->purpose,
                'legal_basis' => $vendorRopa->legal_basis,
                'role' => $vendorRopa->role,
                'data_categories' => $vendorRopa->data_categories ?? [],
                'data_subjects' => $vendorRopa->data_subjects ?? [],
                'retention_period' => $vendorRopa->retention_period,
                'storage_locations' => $vendorRopa->storage_locations ?? [],
                'cross_border' => (bool) $vendorRopa->cross_border,
                'cross_border_countries' => $vendorRopa->cross_border_countries ?? [],
                'sub_processors' => $vendorRopa->sub_processors ?? [],
                'security_measures' => $vendorRopa->security_measures ?? [],
                'notes' => $vendorRopa->notes,
                'pic_name' => $vendorRopa->pic_name,
                'pic_email' => $vendorRopa->pic_email,
                'pic_phone' => $vendorRopa->pic_phone,
            ],
        ];
    }

    public function show(Request $request, string $token)
    {
        /** @var VendorRopa $vendorRopa */
        $vendorRopa = $request->get('_vendorRopa');

        return response()->json(['data' => $this->present($vendorRopa)]);
    }

    /** Simpan draf — boleh berulang selama tautan belum dipakai mengirim. */
    public function saveDraft(Request $request, string $token)
    {
        /** @var VendorRopa $vendorRopa */
        $vendorRopa = $request->get('_vendorRopa');
        $data = $request->validate($this->contentRules(false));

        $vendorRopa->fill($data)->save();

        return response()->json([
            'message' => 'Draf tersimpan.',
            'data' => $this->present($vendorRopa->fresh()),
        ]);
    }

    /** Kirim sekali — setelah ini tautan terkunci. */
    public function submit(Request $request, string $token, VendorRopaTokenService $tokens)
    {
        /** @var VendorRopa $vendorRopa */
        $vendorRopa = $request->get('_vendorRopa');
        $data = $request->validate($this->contentRules(true));

        DB::transaction(function () use ($vendorRopa, $data, $request, $tokens) {
            $vendorRopa->fill($data)->save();
            $tokens->markConsumed($vendorRopa, $request);
        });

        return response()->json([
            'message' => 'RoPA berhasil dikirim. Terima kasih — pengendali data akan meninjaunya.',
            'data' => [
                'submitted_at' => optional($vendorRopa->fresh()->submitted_at)->toIso8601String(),
                'result_url' => url('/api/ropa-pihak-ketiga/'.$token.'/hasil'),
            ],
        ]);
    }

    /** Halaman hasil read-only; hanya ada setelah dikirim. */
    public function hasil(Request $request, string $token)
    {
        /** @var VendorRopa $vendorRopa */
        $vendorRopa = $request->get('_vendorRopa');

        if (! $vendorRopa->isLocked()) {
            return response()->json(['error' => 'RoPA belum dikirim.'], 404);
        }

        return response()->json([
            'data' => $this->present($vendorRopa) + [
                'permintaan_ubah' => $vendorRopa->editRequests()->get(['id', 'status', 'reason', 'created_at', 'decided_at']),
            ],
        ]);
    }

    /**
     * Minta akses ubah setelah terkunci — satu-satunya jalan koreksi bagi pihak
     * ketiga yang tidak punya akun. Yang dikirim hanya ALASAN; bila disetujui,
     * tautan baru dikirim pengendali ke kontak terdaftar, bukan ke alamat mana
     * pun yang diketik di sini.
     */
    public function mintaAksesUbah(Request $request, string $token)
    {
        /** @var VendorRopa $vendorRopa */
        $vendorRopa = $request->get('_vendorRopa');

        if (! $vendorRopa->isLocked()) {
            return response()->json([
                'error' => 'RoPA masih bisa diubah langsung — permintaan akses tidak diperlukan.',
            ], 422);
        }

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        // Satu permintaan tertunda saja; kirim ulang tidak menumpuk antrean.
        $pending = $vendorRopa->editRequests()->where('status', VendorRopaEditRequest::STATUS_PENDING)->first();
        if ($pending) {
            return response()->json([
                'message' => 'Permintaan sebelumnya masih menunggu keputusan pengendali data.',
                'data' => ['id' => $pending->id, 'status' => $pending->status],
            ]);
        }

        $editRequest = VendorRopaEditRequest::create([
            'org_id' => $vendorRopa->org_id,
            'vendor_ropa_id' => $vendorRopa->id,
            'reason' => $data['reason'],
            'requested_ip' => substr((string) $request->ip(), 0, 45),
            'requested_user_agent' => $request->userAgent(),
            'status' => VendorRopaEditRequest::STATUS_PENDING,
        ]);

        AuditLog::create([
            'org_id' => $vendorRopa->org_id,
            'module' => 'tprm.vendor_ropa_edit_request',
            'record_id' => $vendorRopa->id,
            'action' => 'request_edit',
            'user_id' => null,
            'user_name' => 'Public Token',
            'user_role' => 'public_token',
            'section' => 'vendor_ropa',
            'changes' => [
                'request_id' => $editRequest->id,
                'token_prefix' => substr((string) $vendorRopa->access_token, 0, 8),
                'ip' => $request->ip(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Permintaan akses ubah terkirim. Pengendali data akan meninjau dan mengirim tautan baru bila disetujui.',
            'data' => ['id' => $editRequest->id, 'status' => $editRequest->status],
        ], 201);
    }
}
