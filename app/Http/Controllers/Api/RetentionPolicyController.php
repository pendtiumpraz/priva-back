<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RetentionPolicy;
use Illuminate\Http\Request;

/**
 * Retention master-data CRUD (Sprint E3).
 *
 * Tenant-scoped reusable library referenced from RoPA wizard step 7.
 * Supports search (`?q=`), cursor pagination (`?per_page=`), soft-delete +
 * restore, and blocks soft-delete while an active RoPA still references
 * the policy (mirrors ContainmentTemplate in-use pattern).
 */
class RetentionPolicyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = RetentionPolicy::where('org_id', $user->org_id);

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }
        if ($request->filled('q')) {
            $q = $request->get('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('trigger_event', 'like', "%{$q}%");
            });
        }

        $query->orderByDesc('created_at');

        if ($request->filled('per_page')) {
            return response()->json($query->cursorPaginate((int) $request->get('per_page', 20)));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, string $id)
    {
        $policy = RetentionPolicy::where('org_id', $request->user()->org_id)->findOrFail($id);

        return response()->json([
            'data' => $policy,
            'usage_count' => $policy->usageCount(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'duration_value' => 'nullable|integer|min:0|max:999',
            'duration_unit' => 'nullable|in:day,month,year,indefinite',
            'trigger_event' => 'nullable|string|max:255',
            'disposal_method' => 'nullable|in:delete,anonymize,archive',
            'legal_basis' => 'nullable|string|max:255',
        ]);

        $data['org_id'] = $user->org_id;
        $data['created_by'] = $user->id;
        $data['duration_unit'] = $data['duration_unit'] ?? 'year';
        $data['disposal_method'] = $data['disposal_method'] ?? 'delete';

        $policy = RetentionPolicy::create($data);

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $policy = RetentionPolicy::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:2000',
            'duration_value' => 'nullable|integer|min:0|max:999',
            'duration_unit' => 'sometimes|in:day,month,year,indefinite',
            'trigger_event' => 'nullable|string|max:255',
            'disposal_method' => 'sometimes|in:delete,anonymize,archive',
            'legal_basis' => 'nullable|string|max:255',
        ]);

        $policy->update($data);

        return response()->json(['data' => $policy]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $policy = RetentionPolicy::where('org_id', $user->org_id)->findOrFail($id);

        $inUse = $policy->usageCount();
        if ($inUse > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus — masih dipakai oleh {$inUse} RoPA aktif. Lepaskan dari RoPA tersebut dulu.",
                'in_use' => $inUse,
            ], 422);
        }

        $policy->delete();

        return response()->json(['message' => 'Retensi dipindahkan ke trash.']);
    }

    public function restore(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $policy = RetentionPolicy::onlyTrashed()
            ->where('org_id', $user->org_id)
            ->findOrFail($id);

        $policy->restore();

        return response()->json(['data' => $policy, 'message' => 'Retensi dipulihkan.']);
    }

    public function forceDelete(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $policy = RetentionPolicy::withTrashed()
            ->where('org_id', $user->org_id)
            ->findOrFail($id);

        // Also block hard-delete if any RoPA still references it.
        $inUse = $policy->usageCount();
        if ($inUse > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus permanen — masih dipakai oleh {$inUse} RoPA aktif.",
                'in_use' => $inUse,
            ], 422);
        }

        $policy->forceDelete();

        return response()->json(['message' => 'Retensi dihapus permanen.']);
    }

    private function canEdit($user): bool
    {
        return in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true);
    }
}
