<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ropa;
use App\Models\Vendor;
use App\Models\VendorRopa;
use App\Models\VendorRopaEditRequest;
use App\Services\VendorRopaTokenService;
use Illuminate\Http\Request;

/**
 * Sisi pengendali untuk "RoPA Pihak Ketiga": menerbitkan tautan pengisian,
 * meninjau kiriman, menautkannya ke RoPA milik sendiri, dan memutuskan
 * permintaan akses ubah.
 *
 * Penyaringan tenant dilakukan eksplisit (`org_id`) — VendorRopa sengaja tidak
 * memakai global scope karena alur publiknya berjalan tanpa login.
 */
class VendorRopaController extends Controller
{
    /** Daftar untuk tab "RoPA Pihak Ketiga" di TPRM. */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = VendorRopa::query()
            ->where('org_id', $orgId)
            ->whereHas('vendor', fn ($q) => $q->visibleTo($request->user()))
            ->with(['vendor:id,name,country,risk_level'])
            ->withCount('ropas');

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'data' => $query->orderByDesc('updated_at')->paginate((int) $request->input('per_page', 25)),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $vendorRopa = $this->find($request, $id);

        return response()->json([
            'data' => $vendorRopa->load(['vendor:id,name,country,risk_level', 'ropas:id,registration_number,processing_activity', 'editRequests']),
        ]);
    }

    /**
     * Terbitkan tautan pengisian untuk satu pihak ketiga.
     *
     * Baris yang belum terkirim dipakai ulang (token dirotasi → tautan lama
     * otomatis tidak berlaku); bila semuanya sudah terkirim, dibuat baris baru
     * sehingga pihak ketiga bisa mencatat lebih dari satu kegiatan.
     */
    public function issueLink(Request $request, string $vendorId, VendorRopaTokenService $tokens)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($vendorId);

        $vendorRopa = VendorRopa::where('org_id', $vendor->org_id)
            ->where('vendor_id', $vendor->id)
            ->whereNull('token_consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $vendorRopa) {
            $vendorRopa = VendorRopa::create([
                'org_id' => $vendor->org_id,
                'vendor_id' => $vendor->id,
                'role' => Vendor::normalizeRole($vendor->type) ?? Vendor::ROLE_PROCESSOR,
                'status' => VendorRopa::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);
        }

        $token = $tokens->generate($vendorRopa);
        $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:3000'));
        $publicUrl = rtrim((string) $baseUrl, '/').'/ropa-pihak-ketiga/'.$token;

        $this->audit($request, $vendorRopa, 'generate_token', [
            'pihak_ketiga' => $vendor->name,
            'token_prefix' => substr($token, 0, 8),
        ]);

        return response()->json([
            'message' => 'Tautan pengisian RoPA dibuat. Bagikan URL berikut kepada pihak ketiga.',
            'vendor_ropa_id' => $vendorRopa->id,
            'token' => $token,
            'public_url' => $publicUrl,
        ]);
    }

    /** Terima atau kembalikan kiriman pihak ketiga. */
    public function review(Request $request, string $id)
    {
        $vendorRopa = $this->find($request, $id);
        $data = $request->validate([
            'action' => 'required|in:accept,return',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (! $vendorRopa->isLocked()) {
            return response()->json(['message' => 'RoPA ini belum dikirim pihak ketiga.'], 422);
        }

        $vendorRopa->forceFill([
            'status' => $data['action'] === 'accept' ? VendorRopa::STATUS_ACCEPTED : VendorRopa::STATUS_RETURNED,
            'review_notes' => $data['notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit($request, $vendorRopa, 'review', ['action' => $data['action']]);

        return response()->json(['message' => 'Hasil tinjauan tersimpan.', 'data' => $vendorRopa->fresh()]);
    }

    /**
     * Tautkan ke RoPA milik sendiri — inilah jembatan yang membuat insiden di
     * pihak ketiga bisa ditelusuri ke kegiatan pemrosesan tenant.
     */
    public function linkRopas(Request $request, string $id)
    {
        $vendorRopa = $this->find($request, $id);
        $data = $request->validate([
            'ropa_ids' => 'present|array|max:100',
            'ropa_ids.*' => 'uuid',
        ]);

        // Hanya RoPA milik org yang sama — tautan lintas tenant tidak pernah dibuat.
        $valid = Ropa::whereIn('id', $data['ropa_ids'])
            ->where('org_id', $vendorRopa->org_id)
            ->pluck('id')
            ->all();

        $sync = [];
        foreach ($valid as $ropaId) {
            $sync[$ropaId] = ['org_id' => $vendorRopa->org_id];
        }
        $vendorRopa->ropas()->sync($sync);

        $this->audit($request, $vendorRopa, 'link_ropa', ['ropa_ids' => $valid]);

        return response()->json([
            'message' => 'Tautan ke RoPA diperbarui.',
            'data' => $vendorRopa->load('ropas:id,registration_number,processing_activity'),
        ]);
    }

    /** Antrean permintaan akses ubah dari pihak ketiga. */
    public function editRequests(Request $request)
    {
        $query = VendorRopaEditRequest::query()
            ->where('org_id', $request->user()->org_id)
            ->with(['vendorRopa:id,vendor_id,processing_activity,status'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(['data' => $query->paginate((int) $request->input('per_page', 25))]);
    }

    /**
     * Putuskan permintaan akses ubah. Disetujui → token dirotasi sehingga
     * tautan LAMA mati; tautan baru dibagikan pengendali ke kontak terdaftar
     * pihak ketiga (bukan ke alamat yang diketik pemohon).
     */
    public function decideEditRequest(Request $request, string $requestId, VendorRopaTokenService $tokens)
    {
        $editRequest = VendorRopaEditRequest::where('org_id', $request->user()->org_id)->findOrFail($requestId);
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($editRequest->status !== VendorRopaEditRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Permintaan ini sudah diputuskan.'], 422);
        }

        $vendorRopa = VendorRopa::where('org_id', $editRequest->org_id)->findOrFail($editRequest->vendor_ropa_id);
        $publicUrl = null;

        if ($data['action'] === 'approve') {
            $token = $tokens->generate($vendorRopa);
            $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:3000'));
            $publicUrl = rtrim((string) $baseUrl, '/').'/ropa-pihak-ketiga/'.$token;
        }

        $editRequest->forceFill([
            'status' => $data['action'] === 'approve' ? VendorRopaEditRequest::STATUS_APPROVED : VendorRopaEditRequest::STATUS_REJECTED,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_notes' => $data['notes'] ?? null,
        ])->save();

        $this->audit($request, $vendorRopa, 'decide_edit_request', [
            'request_id' => $editRequest->id,
            'action' => $data['action'],
        ]);

        return response()->json([
            'message' => $data['action'] === 'approve'
                ? 'Permintaan disetujui. Kirimkan tautan berikut ke kontak terdaftar pihak ketiga.'
                : 'Permintaan ditolak.',
            'data' => ['status' => $editRequest->status, 'public_url' => $publicUrl],
        ]);
    }

    private function find(Request $request, string $id): VendorRopa
    {
        return VendorRopa::where('org_id', $request->user()->org_id)
            ->whereHas('vendor', fn ($q) => $q->visibleTo($request->user()))
            ->findOrFail($id);
    }

    /** @param array<string, mixed> $changes */
    private function audit(Request $request, VendorRopa $vendorRopa, string $action, array $changes): void
    {
        AuditLog::create([
            'org_id' => $vendorRopa->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'tprm.vendor_ropa',
            'action' => $action,
            'record_id' => $vendorRopa->id,
            'section' => 'vendor_ropa',
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
