<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use Illuminate\Http\Request;

/**
 * API publik v1 — Pihak Ketiga.
 *
 * Dipakai sistem pengadaan tenant untuk mengalirkan daftar rekanannya ke
 * Privasimu tanpa entri ulang. Autentikasi lewat header `X-Api-Key`
 * (AuthenticatePartnerApi): kunci membawa org, izin, batas laju, dan jejak
 * permintaannya sendiri, jadi controller ini cukup menyaring dengan org kunci.
 *
 * Penulisan bersifat UPSERT pada `external_ref` (id rekanan di sistem asal):
 * mengirim ulang rekanan yang sama memperbarui barisnya, bukan menggandakan
 * registri. Tanpa itu, integrasi yang mengirim ulang seluruh katalog setiap
 * malam akan menumpuk ribuan kembaran.
 */
class ThirdPartyApiV1Controller extends Controller
{
    private function orgId(Request $request): string
    {
        return $request->attributes->get('api_org_id');
    }

    /** GET /api/v1/third-parties */
    public function index(Request $request)
    {
        $query = Vendor::where('org_id', $this->orgId($request));

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->country) {
            $query->where('country', $request->country);
        }
        if ($request->risk_level) {
            $query->where('risk_level', $request->risk_level);
        }
        if ($request->lifecycle_status) {
            $query->where('lifecycle_status', $request->lifecycle_status);
        }
        if ($request->external_ref) {
            $query->where('external_ref', $request->external_ref);
        }
        if ($request->since) {
            $query->where('updated_at', '>=', $request->since);
        }

        $items = $query->orderBy($request->sort ?? 'created_at', $request->order ?? 'desc')
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

    /** GET /api/v1/third-parties/{id} */
    public function show(string $id, Request $request)
    {
        $vendor = Vendor::where('org_id', $this->orgId($request))->findOrFail($id);

        return response()->json(['data' => $vendor]);
    }

    /**
     * POST /api/v1/third-parties
     *
     * Membuat baru, atau memperbarui bila `external_ref` sudah dikenal.
     */
    public function store(Request $request)
    {
        $orgId = $this->orgId($request);
        $data = $request->validate($this->rules());

        if (isset($data['type'])) {
            $data['type'] = Vendor::normalizeRole($data['type']) ?? Vendor::ROLE_PROCESSOR;
        }

        $existing = ! empty($data['external_ref'])
            ? Vendor::where('org_id', $orgId)->where('external_ref', $data['external_ref'])->first()
            : null;

        if ($existing) {
            $existing->fill($data)->save();
            $this->audit($existing, 'api_updated', $request);

            return response()->json([
                'message' => 'Pihak ketiga diperbarui.',
                'data' => $existing->fresh(),
                'created' => false,
            ]);
        }

        $vendor = Vendor::create($data + [
            'org_id' => $orgId,
            // Tanpa ini baris hasil integrasi tidak terlihat pengguna non-admin.
            'assign_group' => '(All Group)',
            'pdp_scope_status' => Vendor::SCOPE_UNSCREENED,
        ]);
        $this->audit($vendor, 'api_created', $request);

        return response()->json([
            'message' => 'Pihak ketiga dibuat.',
            'data' => $vendor,
            'created' => true,
        ], 201);
    }

    /** PUT /api/v1/third-parties/{id} */
    public function update(string $id, Request $request)
    {
        $orgId = $this->orgId($request);
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($id);
        $data = $request->validate($this->rules(true));

        if (isset($data['type'])) {
            $data['type'] = Vendor::normalizeRole($data['type']) ?? Vendor::ROLE_PROCESSOR;
        }

        $vendor->fill($data)->save();
        $this->audit($vendor, 'api_updated', $request);

        return response()->json(['message' => 'Pihak ketiga diperbarui.', 'data' => $vendor->fresh()]);
    }

    /** @return array<string, string> */
    private function rules(bool $forUpdate = false): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';

        return [
            'name' => "{$req}|string|max:255",
            'external_ref' => 'nullable|string|max:120',
            'type' => 'nullable|string|max:32',
            'category' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:500',
            'privacy_policy_url' => 'nullable|url|max:500',
            'description' => 'nullable|string|max:2000',
            'contact_name' => 'nullable|string|max:200',
            'contact_email' => 'nullable|email|max:200',
            'telepon' => 'nullable|string|max:50',
            'npwp' => 'nullable|string|max:50',
            'alamat' => 'nullable|string|max:1000',
            'pic_jabatan' => 'nullable|string|max:255',
            'departemen_kontak' => 'nullable|string|max:255',
            'services_provided' => 'nullable|array',
            'services_provided.*' => 'string|max:200',
            'data_shared' => 'nullable|array',
            'data_shared.*' => 'string|max:200',
            'dpa_status' => 'nullable|in:none,draft,signed,expired',
            'dpa_signed_at' => 'nullable|date',
            'dpa_expires_at' => 'nullable|date',
        ];
    }

    /**
     * `audit_logs` tidak punya kolom org_id — keterkaitan tenant ditelusuri
     * lewat record_id → vendors.org_id, sama seperti jalur CRUD lainnya.
     * Pelaku ditulis sebagai kunci API, bukan 'System', supaya jejaknya bisa
     * dibedakan dari perubahan oleh manusia.
     */
    private function audit(Vendor $vendor, string $action, Request $request): void
    {
        $key = $request->attributes->get('api_key');

        AuditLog::create([
            'module' => 'vendor_risk',
            'action' => $action,
            'record_id' => $vendor->id,
            'section' => 'api',
            'user_id' => null,
            'user_name' => 'API: '.($key->name ?? 'Partner'),
            'user_role' => 'api_key',
            'changes' => ['name' => $vendor->name, 'external_ref' => $vendor->external_ref],
            'ip_address' => $request->ip(),
        ]);
    }
}
