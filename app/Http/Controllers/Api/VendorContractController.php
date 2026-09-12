<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use App\Services\VendorContractTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Kontrak pihak ketiga: pencatatan, unggahan dua arah, lini masa masa berlaku,
 * dan penerusan ke Contract Review.
 */
class VendorContractController extends Controller
{
    /**
     * Daftar kontrak + data lini masa. Lini masa memakai kolom tanggal, jadi
     * satu permintaan cukup untuk menggambar kalender seluruh pihak ketiga.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = VendorContract::query()
            ->where('org_id', $orgId)
            ->whereHas('vendor', fn ($q) => $q->visibleTo($request->user()))
            ->with('vendor:id,name,lifecycle_status');

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('expiring')) {
            $query->whereNotNull('end_at')->whereBetween('end_at', [now()->startOfDay(), now()->addDays(90)->endOfDay()]);
        }

        $items = $query->orderByRaw('end_at is null, end_at asc')->get();

        return response()->json([
            'data' => $items,
            'ringkasan' => [
                'total' => $items->count(),
                'tanpa_berkas' => $items->filter(fn ($c) => ! $c->has_file)->count(),
                'akan_berakhir_90_hari' => $items->filter(fn ($c) => $c->days_to_expiry !== null && $c->days_to_expiry >= 0 && $c->days_to_expiry <= 90)->count(),
                'sudah_berakhir' => $items->filter(fn ($c) => $c->days_to_expiry !== null && $c->days_to_expiry < 0)->count(),
            ],
        ]);
    }

    public function store(Request $request, string $vendorId)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($vendorId);
        $data = $request->validate($this->rules(false));

        $contract = VendorContract::create($data + [
            'org_id' => $vendor->org_id,
            'vendor_id' => $vendor->id,
            'status' => $data['status'] ?? VendorContract::STATUS_DRAFT,
        ]);

        $this->audit($request, $contract, 'create', ['title' => $contract->title]);

        return response()->json(['message' => 'Kontrak dicatat.', 'data' => $contract], 201);
    }

    public function update(Request $request, string $id)
    {
        $contract = $this->find($request, $id);
        $contract->fill($request->validate($this->rules(true)))->save();

        $this->audit($request, $contract, 'update', ['title' => $contract->title]);

        return response()->json(['message' => 'Kontrak diperbarui.', 'data' => $contract->fresh()]);
    }

    /** Unggahan dari sisi perusahaan. */
    public function upload(Request $request, string $id, TenantStorageService $storage, FileUploadValidator $validator)
    {
        $contract = $this->find($request, $id);
        $request->validate(['file' => 'required|file|max:10240']);

        try {
            $validator->validate($request->file('file'), FileUploadValidator::PRESET_DOCUMENT);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = Organization::findOrFail($contract->org_id);
        $stored = $storage->storeTenantPrivateFile($org, $request->file('file'), "vendors/{$contract->vendor_id}/contracts");

        $contract->forceFill([
            'file' => [
                'path' => $stored['path'],
                'driver' => $stored['driver'],
                'filename' => $request->file('file')->getClientOriginalName(),
                'size' => $request->file('file')->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ],
            'uploaded_side' => VendorContract::SIDE_TENANT,
            'uploaded_by' => $request->user()->id,
        ])->save();

        $this->audit($request, $contract, 'upload', ['filename' => $request->file('file')->getClientOriginalName()]);

        return response()->json(['message' => 'Berkas kontrak tersimpan.', 'data' => $contract->fresh()]);
    }

    /**
     * Terbitkan tautan agar PIHAK KETIGA yang mengunggah kontraknya — dipakai
     * bila perusahaan sendiri tidak memegang berkasnya.
     */
    public function issueUploadLink(Request $request, string $id, VendorContractTokenService $tokens)
    {
        $contract = $this->find($request, $id);
        $token = $tokens->generate($contract);
        $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:3000'));

        $this->audit($request, $contract, 'issue_upload_link', ['token_prefix' => substr($token, 0, 8)]);

        return response()->json([
            'message' => 'Tautan unggah dibuat. Bagikan kepada pihak ketiga.',
            'public_url' => rtrim((string) $baseUrl, '/').'/kontrak-pihak-ketiga/'.$token,
        ]);
    }

    /**
     * Teruskan kontrak ke Contract Review (telaah kepatuhan berbantuan AI).
     * Baris telaah dibuat berstatus menunggu dan menunjuk berkas yang sama,
     * sehingga tidak ada unggahan ganda.
     */
    public function sendToReview(Request $request, string $id)
    {
        $contract = $this->find($request, $id);

        if (! $contract->has_file) {
            return response()->json(['message' => 'Unggah berkas kontrak terlebih dahulu.'], 422);
        }
        if ($contract->contract_review_id) {
            return response()->json([
                'message' => 'Kontrak ini sudah dikirim ke Contract Review.',
                'data' => ['contract_review_id' => $contract->contract_review_id],
            ]);
        }

        $reviewId = (string) Str::uuid();
        DB::table('contract_reviews')->insert([
            'id' => $reviewId,
            'org_id' => $contract->org_id,
            'title' => $contract->title,
            'contract_type' => $contract->contract_type,
            'file_path' => $contract->file['path'] ?? null,
            'file_name' => $contract->file['filename'] ?? null,
            'status' => 'pending',
            'risk_score' => 0,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract->forceFill(['contract_review_id' => $reviewId])->save();
        $this->audit($request, $contract, 'send_to_review', ['contract_review_id' => $reviewId]);

        return response()->json([
            'message' => 'Kontrak dikirim ke Contract Review.',
            'data' => ['contract_review_id' => $reviewId],
        ], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $contract = $this->find($request, $id);
        $contract->delete();
        $this->audit($request, $contract, 'delete', []);

        return response()->json(['message' => 'Kontrak dihapus.']);
    }

    /** @return array<string, string> */
    private function rules(bool $forUpdate): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';
        $opt = $forUpdate ? 'sometimes|nullable' : 'nullable';

        return [
            'title' => "{$req}|string|max:255",
            'contract_type' => "{$opt}|in:".implode(',', VendorContract::TYPES),
            'contract_number' => "{$opt}|string|max:100",
            'start_at' => "{$opt}|date",
            'end_at' => "{$opt}|date|after_or_equal:start_at",
            'auto_renew' => "{$opt}|boolean",
            'notice_days' => "{$opt}|integer|min:0|max:365",
            'status' => "{$opt}|in:".implode(',', VendorContract::STATUSES),
            'notes' => "{$opt}|string|max:2000",
        ];
    }

    private function find(Request $request, string $id): VendorContract
    {
        return VendorContract::where('org_id', $request->user()->org_id)
            ->whereHas('vendor', fn ($q) => $q->visibleTo($request->user()))
            ->findOrFail($id);
    }

    /** @param array<string, mixed> $changes */
    private function audit(Request $request, VendorContract $contract, string $action, array $changes): void
    {
        AuditLog::create([
            'org_id' => $contract->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'tprm.contract',
            'action' => $action,
            'record_id' => $contract->id,
            'section' => 'vendor_contract',
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
