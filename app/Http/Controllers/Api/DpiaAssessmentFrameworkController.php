<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DpiaCategory;
use App\Models\DpiaCategoryRisk;
use App\Models\DpiaScoringGuidance;
use App\Services\DpiaCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for tenant-customizable DPIA assessment framework:
 *   - Categories (aspek penilaian)
 *   - Default risks per category (peristiwa risiko)
 *
 * List endpoint is read-permission; create/update/delete gated to DPO role.
 * First call auto-seeds the 21 Nexus UU PDP categories + 5 default risks each.
 */
class DpiaAssessmentFrameworkController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) return response()->json(['data' => []]);

        DpiaCategoryService::ensureSeeded($orgId);

        $categories = DpiaCategory::where('org_id', $orgId)
            ->where('is_active', true)
            ->with('risks')
            ->orderBy('sequence')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'sequence' => $c->sequence,
                'risks' => $c->risks->map(fn ($r) => [
                    'id' => $r->id,
                    'risk_event' => $r->risk_event,
                    'description' => $r->description,
                    'sequence' => $r->sequence,
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $categories]);
    }

    // ===== Category CRUD =====
    public function storeCategory(Request $request)
    {
        $this->assertDPO($request);
        $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'sequence' => 'nullable|integer',
        ]);

        $orgId = $request->user()->org_id;
        DpiaCategoryService::ensureSeeded($orgId);
        $seq = $request->input('sequence') ?? (DpiaCategory::where('org_id', $orgId)->max('sequence') + 1);

        $cat = DpiaCategory::create([
            'org_id' => $orgId,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'sequence' => $seq,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $cat], 201);
    }

    public function updateCategory(Request $request, string $id)
    {
        $this->assertDPO($request);
        $request->validate([
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'sequence' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $cat = DpiaCategory::where('org_id', $request->user()->org_id)->findOrFail($id);
        $cat->update(array_merge(
            $request->only(['name', 'description', 'sequence', 'is_active']),
            ['updated_by' => $request->user()->id]
        ));
        return response()->json(['data' => $cat->fresh()]);
    }

    public function destroyCategory(Request $request, string $id)
    {
        $this->assertDPO($request);
        $cat = DpiaCategory::where('org_id', $request->user()->org_id)->findOrFail($id);
        DB::transaction(function () use ($cat) {
            DpiaCategoryRisk::where('category_id', $cat->id)->delete();
            $cat->delete();
        });
        return response()->json(['deleted' => true]);
    }

    // ===== Risk Event CRUD =====
    public function storeRisk(Request $request, string $categoryId)
    {
        $this->assertDPO($request);
        $request->validate([
            'risk_event' => 'required|string|max:400',
            'description' => 'nullable|string|max:2000',
            'sequence' => 'nullable|integer',
        ]);

        $cat = DpiaCategory::where('org_id', $request->user()->org_id)->findOrFail($categoryId);
        $seq = $request->input('sequence') ?? (DpiaCategoryRisk::where('category_id', $cat->id)->max('sequence') + 1);

        $risk = DpiaCategoryRisk::create([
            'org_id' => $request->user()->org_id,
            'category_id' => $cat->id,
            'risk_event' => $request->input('risk_event'),
            'description' => $request->input('description'),
            'sequence' => $seq,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $risk], 201);
    }

    public function updateRisk(Request $request, string $categoryId, string $id)
    {
        $this->assertDPO($request);
        $request->validate([
            'risk_event' => 'nullable|string|max:400',
            'description' => 'nullable|string|max:2000',
            'sequence' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $risk = DpiaCategoryRisk::where('org_id', $request->user()->org_id)
            ->where('category_id', $categoryId)
            ->findOrFail($id);
        $risk->update(array_merge(
            $request->only(['risk_event', 'description', 'sequence', 'is_active']),
            ['updated_by' => $request->user()->id]
        ));
        return response()->json(['data' => $risk->fresh()]);
    }

    public function destroyRisk(Request $request, string $categoryId, string $id)
    {
        $this->assertDPO($request);
        $risk = DpiaCategoryRisk::where('org_id', $request->user()->org_id)
            ->where('category_id', $categoryId)
            ->findOrFail($id);
        $risk->delete();
        return response()->json(['deleted' => true]);
    }

    // ===== Reset to system defaults =====
    public function reset(Request $request)
    {
        $this->assertDPO($request);
        $orgId = $request->user()->org_id;
        DB::transaction(function () use ($orgId) {
            // Wipe tenant's custom categories (cascade wipes risks via FK).
            DpiaCategory::where('org_id', $orgId)->get()->each->delete();
        });
        DpiaCategoryService::ensureSeeded($orgId);
        return response()->json(['message' => 'Framework direset ke default sistem.']);
    }

    /**
     * GET /dpia/framework/scoring-guidance
     * Override "Panduan Nilai" per tenant. Null kalau belum di-custom → frontend
     * pakai default bawaan.
     */
    public function scoringGuidance(Request $request)
    {
        $row = DpiaScoringGuidance::where('org_id', $request->user()->org_id)->first();

        return response()->json(['data' => $row?->payload]);
    }

    /**
     * PUT /dpia/framework/scoring-guidance
     * Simpan/override panduan nilai. payload = { dampak, probabilitas, kontrol, penanganan }.
     */
    public function updateScoringGuidance(Request $request)
    {
        $this->assertDPO($request);

        $data = $request->validate([
            'payload' => ['required', 'array'],
            'payload.dampak' => ['sometimes', 'array'],
            'payload.dampak.*.indikator' => ['required_with:payload.dampak', 'string', 'max:255'],
            'payload.dampak.*.levels' => ['required_with:payload.dampak', 'array'],
            'payload.probabilitas' => ['sometimes', 'array'],
            'payload.kontrol' => ['sometimes', 'array'],
            'payload.penanganan' => ['sometimes', 'array'],
        ]);

        $row = DpiaScoringGuidance::updateOrCreate(
            ['org_id' => $request->user()->org_id],
            ['payload' => $data['payload'], 'updated_by' => $request->user()->id],
        );

        return response()->json(['message' => 'Panduan nilai disimpan.', 'data' => $row->payload]);
    }

    /**
     * DELETE /dpia/framework/scoring-guidance — kembalikan ke default (hapus override).
     */
    public function resetScoringGuidance(Request $request)
    {
        $this->assertDPO($request);
        DpiaScoringGuidance::where('org_id', $request->user()->org_id)->delete();

        return response()->json(['message' => 'Panduan nilai dikembalikan ke default.']);
    }

    private function assertDPO(Request $request): void
    {
        $user = $request->user();
        $role = $user->role ?? '';
        $tenantRoleName = strtolower((string) optional($user->tenantRole)->name);
        $isDPO = in_array($role, ['root', 'superadmin', 'dpo'], true) || str_contains($tenantRoleName, 'dpo');
        if (!$isDPO) {
            abort(403, 'Hanya DPO yang dapat mengubah framework DPIA.');
        }
    }
}
