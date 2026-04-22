<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RaciTemplate;
use Illuminate\Http\Request;

/**
 * RACI template library CRUD (Phase G1).
 *
 * Tenant-scoped. System templates (org_id=null) read-only for tenants, forked
 * on save via the same copy-on-write pattern as ContainmentTemplate.
 *
 * Endpoints:
 *   GET    /raci-templates              → [system presets + tenant custom]
 *   GET    /raci-templates/{id}         → single (with usage count)
 *   POST   /raci-templates              → tenant create
 *   PUT    /raci-templates/{id}         → update (auto-fork system presets)
 *   DELETE /raci-templates/{id}         → soft-delete (blocked if in use)
 *   POST   /raci-templates/{id}/restore → restore from trash
 *   DELETE /raci-templates/{id}/force   → hard-delete
 *   GET    /raci-templates/trash        → list trashed
 */
class RaciTemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $rows = RaciTemplate::where(function ($q) use ($orgId) {
                $q->whereNull('org_id')->orWhere('org_id', $orgId);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function trash(Request $request)
    {
        $user = $request->user();
        $rows = RaciTemplate::onlyTrashed()
            ->where('org_id', $user->org_id)
            ->orderByDesc('deleted_at')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = RaciTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($id);

        return response()->json([
            'data' => $tpl,
            'usage_count' => $tpl->usageCount(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'matrix' => 'required|array',
            'matrix.*.responsible' => 'nullable|string|max:100',
            'matrix.*.accountable' => 'nullable|string|max:100',
            'matrix.*.consulted' => 'nullable|array',
            'matrix.*.informed' => 'nullable|array',
            'clone_from' => 'nullable|uuid',
        ]);

        $matrix = $this->normalizeMatrix($data['matrix']);
        if (!empty($data['clone_from'])) {
            $src = RaciTemplate::where(function ($q) use ($user) {
                    $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
                })->find($data['clone_from']);
            if ($src) {
                $matrix = array_merge($src->matrix ?? [], $matrix);
            }
        }

        $tpl = RaciTemplate::create([
            'org_id' => $user->org_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'matrix' => $matrix,
            'is_system' => false,
            'is_default' => false,
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => $tpl], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = RaciTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:2000',
            'matrix' => 'sometimes|array',
        ]);

        $matrix = isset($data['matrix']) ? $this->normalizeMatrix($data['matrix']) : null;

        // Copy-on-write for system presets (tenant edit → auto-fork).
        if ($tpl->is_system) {
            // Platform root/superadmin at no-org scope may edit system directly.
            if (in_array($user->role, ['root', 'superadmin'], true) && !$user->org_id) {
                $tpl->update(array_filter([
                    'name' => $data['name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'matrix' => $matrix,
                ], fn($v) => !is_null($v)));
                return response()->json(['data' => $tpl]);
            }

            $fork = RaciTemplate::create([
                'org_id' => $user->org_id,
                'name' => $data['name'] ?? $tpl->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $tpl->description,
                'matrix' => $matrix ?? $tpl->matrix,
                'is_system' => false,
                'is_default' => false,
                'created_by' => $user->id,
            ]);
            return response()->json(['data' => $fork, 'forked_from' => $tpl->id]);
        }

        $tpl->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : null,
            'matrix' => $matrix,
        ], fn($v) => !is_null($v)));

        return response()->json(['data' => $tpl]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = RaciTemplate::where('org_id', $user->org_id)->findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Template sistem tidak bisa dihapus — klik Edit untuk fork.'], 422);
        }

        $inUse = $tpl->usageCount();
        if ($inUse > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus — kategori di template ini masih dipakai {$inUse} breach aktif.",
                'in_use' => $inUse,
            ], 422);
        }

        $tpl->delete();
        return response()->json(['message' => 'Template RACI dipindahkan ke trash.']);
    }

    public function restore(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = RaciTemplate::onlyTrashed()
            ->where('org_id', $user->org_id)
            ->findOrFail($id);
        $tpl->restore();
        return response()->json(['data' => $tpl, 'message' => 'Template dipulihkan.']);
    }

    public function forceDelete(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = RaciTemplate::withTrashed()->where('org_id', $user->org_id)->findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Template sistem tidak bisa di-hard-delete.'], 422);
        }
        $tpl->forceDelete();
        return response()->json(['message' => 'Template dihapus permanen.']);
    }

    private function canEdit($user): bool
    {
        return in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true);
    }

    /**
     * Normalize a matrix payload: ensure each category has the 4 RACI keys
     * with correct types (strings + arrays). Drops unknown extra fields.
     */
    private function normalizeMatrix(array $matrix): array
    {
        $out = [];
        foreach ($matrix as $category => $entry) {
            if (!is_string($category) || !is_array($entry)) continue;
            $out[$category] = [
                'responsible' => (string) ($entry['responsible'] ?? ''),
                'accountable' => (string) ($entry['accountable'] ?? ''),
                'consulted'   => array_values(array_filter(array_map('strval', (array) ($entry['consulted'] ?? [])))),
                'informed'    => array_values(array_filter(array_map('strval', (array) ($entry['informed'] ?? [])))),
            ];
        }
        return $out;
    }
}
