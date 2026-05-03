<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModuleCustomSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD for org-custom wizard sections (CUSTOM_WIZARD_PLAN.md Phase 2 / §5.1).
 *
 * Write paths require admin / superadmin / root. The plan suggests an
 * eventual `wizard_schema,write` permission gate; until that's seeded we
 * fall back to `is admin/dpo` since these are the same actors who already
 * manage `module_custom_fields`.
 *
 * Multi-tenant: every query filters by the caller's org_id. Section-level
 * uniqueness is `(org_id, module, section_key)`.
 */
class CustomSectionController extends Controller
{
    private const ALLOWED_MODULES = ['ropa', 'dpia'];

    private const SECTION_KEY_REGEX = '/^[a-z][a-z0-9_]*$/';

    /**
     * Returns null when the user is allowed; otherwise a 403 JsonResponse.
     */
    private function authorizeWrite(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true)) {
            return null;
        }

        // Check tenant_role wildcard / explicit grants.
        if (! $user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }
        $perms = $user->tenantRole?->permissions ?? null;
        if (is_array($perms)) {
            if (in_array('*', $perms, true) ||
                in_array('wizard_schema:write', $perms, true) ||
                in_array('settings:write', $perms, true)) {
                return null;
            }
        }

        return response()->json([
            'message' => 'Akses ditolak — hanya admin/DPO yang boleh mengubah skema wizard.',
        ], 403);
    }

    public function index(Request $request): JsonResponse
    {
        $module = $request->query('module');
        if (! in_array($module, self::ALLOWED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $sections = ModuleCustomSection::forOrg($request->user()->org_id)
            ->forModule($module)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $sections]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeWrite($request)) {
            return $denied;
        }

        $data = $request->validate([
            'module' => ['required', Rule::in(self::ALLOWED_MODULES)],
            'section_key' => ['required', 'string', 'max:64', 'regex:'.self::SECTION_KEY_REGEX],
            'section_label' => 'required|string|max:191',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0|max:99999',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $request->user()->org_id;

        $exists = ModuleCustomSection::forOrg($orgId)
            ->forModule($data['module'])
            ->where('section_key', $data['section_key'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Section key sudah dipakai di module ini.',
            ], 422);
        }

        $section = ModuleCustomSection::create([
            'org_id' => $orgId,
            'module' => $data['module'],
            'section_key' => $data['section_key'],
            'section_label' => $data['section_label'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? (
                (int) ModuleCustomSection::forOrg($orgId)->forModule($data['module'])->max('sort_order') + 1
            ),
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit($section, 'created', $section->only([
            'module', 'section_key', 'section_label', 'sort_order',
        ]));

        return response()->json([
            'message' => 'Section dibuat.',
            'data' => $section,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->authorizeWrite($request)) {
            return $denied;
        }

        $section = ModuleCustomSection::forOrg($request->user()->org_id)->findOrFail($id);

        $data = $request->validate([
            'section_label' => 'sometimes|string|max:191',
            'description' => 'nullable|string',
            'sort_order' => 'sometimes|integer|min:0|max:99999',
            'is_active' => 'sometimes|boolean',
        ]);

        $before = $section->only(array_keys($data));
        $section->update($data);
        $after = $section->fresh()->only(array_keys($data));

        $this->audit($section, 'updated', ['before' => $before, 'after' => $after]);

        return response()->json([
            'message' => 'Section diperbarui.',
            'data' => $section->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->authorizeWrite($request)) {
            return $denied;
        }

        $section = ModuleCustomSection::forOrg($request->user()->org_id)->findOrFail($id);
        $sectionKey = $section->section_key;
        $module = $section->module;
        $section->delete();

        $this->audit($section, 'deleted', [
            'section_key' => $sectionKey,
            'module' => $module,
        ]);

        return response()->json(['message' => 'Section dihapus.']);
    }

    /**
     * Bulk reorder. Body: { items: [{ id, sort_order }, ...] }.
     * Plain top-level array body is also accepted for convenience.
     */
    public function reorder(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeWrite($request)) {
            return $denied;
        }

        $payload = $request->input('items', $request->all());
        $request->merge(['items' => $payload]);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|string|max:64',
            'items.*.sort_order' => 'required|integer|min:0|max:99999',
        ]);

        $orgId = $request->user()->org_id;
        $ids = collect($data['items'])->pluck('id')->all();

        $sections = ModuleCustomSection::forOrg($orgId)->whereIn('id', $ids)->get()->keyBy('id');
        if ($sections->count() !== count($ids)) {
            return response()->json([
                'message' => 'Salah satu section tidak ditemukan dalam org ini.',
            ], 404);
        }

        DB::transaction(function () use ($data, $sections) {
            foreach ($data['items'] as $item) {
                $sec = $sections->get($item['id']);
                if ($sec && (int) $sec->sort_order !== (int) $item['sort_order']) {
                    $sec->update(['sort_order' => (int) $item['sort_order']]);
                }
            }
        });

        $this->bulkAudit('section.reorder', $data['items']);

        return response()->json([
            'message' => 'Urutan section diperbarui.',
            'data' => ModuleCustomSection::forOrg($orgId)
                ->whereIn('id', $ids)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Audit helpers
    // ---------------------------------------------------------------------

    private function audit(ModuleCustomSection $section, string $action, array $changes): void
    {
        try {
            AuditLog::log(
                'wizard_schema',
                $section->id,
                $action,
                array_merge($changes, [
                    'module' => $section->module,
                    'section_key' => $section->section_key,
                ]),
                'section',
            );
        } catch (\Throwable $e) {
            \Log::warning('wizard_schema audit log failed: '.$e->getMessage());
        }
    }

    private function bulkAudit(string $action, array $items): void
    {
        try {
            AuditLog::log('wizard_schema', 'bulk', $action, ['items' => $items], 'section');
        } catch (\Throwable $e) {
            \Log::warning('wizard_schema bulk audit log failed: '.$e->getMessage());
        }
    }
}
