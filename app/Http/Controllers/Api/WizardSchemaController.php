<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WizardSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the merged (built-in + org-custom) wizard schema for a module.
 * Read-only — any authenticated user with access to the module can fetch.
 *
 * See CUSTOM_WIZARD_PLAN.md §5.1 / §5.2.
 */
class WizardSchemaController extends Controller
{
    public function __construct(private readonly WizardSchemaService $schema) {}

    public function show(Request $request, string $module): JsonResponse
    {
        if (! in_array($module, WizardSchemaService::SUPPORTED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $user = $request->user();
        $orgId = $user?->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Tenant context missing.'], 403);
        }

        return response()->json([
            'data' => $this->schema->getSchema($orgId, $module),
            // State field built-in (aktif/label) untuk gating wizard tanpa ubah
            // tampilan. Kosong = org belum kustomisasi → semua field aktif.
            'built_in_fields' => $this->schema->getBuiltInFieldStates($orgId, $module),
        ]);
    }

    /**
     * Schema lengkap untuk EDITOR Master Schema (semua field built-in + custom,
     * aktif + nonaktif). Auto-seed default kalau org belum punya.
     */
    public function editor(Request $request, string $module): JsonResponse
    {
        if (! in_array($module, WizardSchemaService::SUPPORTED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }
        $user = $request->user();
        $orgId = $user?->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Tenant context missing.'], 403);
        }
        if (! $this->canManageSchema($user)) {
            return response()->json(['message' => 'Tidak punya izin mengelola schema.'], 403);
        }

        return response()->json(['data' => $this->schema->getEditorSchema($orgId, $module)]);
    }

    /**
     * Reset schema module ke default kanonik: hapus semua kustomisasi org
     * (built-in override + custom) lalu seed ulang dari RopaDefaultSchema.
     * Hanya untuk role pengelola schema (admin/dpo/root/superadmin) atau
     * permission wizard_schema:write / settings:write.
     */
    public function reset(Request $request, string $module): JsonResponse
    {
        if (! in_array($module, WizardSchemaService::SUPPORTED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $user = $request->user();
        $orgId = $user?->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Tenant context missing.'], 403);
        }

        if (! $this->canManageSchema($user)) {
            return response()->json(['message' => 'Tidak punya izin mengelola schema.'], 403);
        }

        $this->schema->resetToDefault($orgId, $module);

        \App\Models\AuditLog::log('wizard_schema', $module, 'schema.reset', ['module' => $module]);

        return response()->json([
            'message' => 'Schema dikembalikan ke default.',
            'data' => $this->schema->getSchema($orgId, $module),
        ]);
    }

    private function canManageSchema($user): bool
    {
        if (! $user) {
            return false;
        }
        if (in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true)) {
            return true;
        }
        $perms = $user->tenantRole?->permissions;
        if (is_array($perms)) {
            return in_array('*', $perms, true)
                || in_array('wizard_schema:write', $perms, true)
                || in_array('settings:write', $perms, true);
        }

        return false;
    }
}
