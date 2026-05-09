<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflowConfig;
use App\Models\TenantRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD konfigurasi Approval Workflow per module untuk tenant admin.
 *
 * Endpoint:
 *   GET    /approval-configs                          → list semua module + config
 *   GET    /approval-configs/{module}                 → config detail satu module
 *   PUT    /approval-configs/{module}                 → upsert config (enabled, steps[])
 *   GET    /approval-configs/eligible-roles/{module}  → daftar tenant_roles yang punya
 *                                                       permission `<module>:approve`
 */
class ApprovalConfigController extends Controller
{
    /**
     * Module yang support workflow config.
     * LIA/TIA pakai mekanisme Maker-Checker-Approver sendiri.
     * Vendor Risk & Cross-Border: future extension (controller bespoke).
     */
    public const SUPPORTED_MODULES = [
        'ropa' => 'RoPA (Catatan Pemrosesan)',
        'dpia' => 'DPIA (Penilaian Dampak)',
        'breach' => 'Insiden Pelanggaran (Breach)',
        'dsr' => 'Permintaan Hak Subjek (DSR)',
        'cross_border' => 'Transfer Lintas Negara',
        'vendor_risk' => 'Vendor Risk Assessment',
    ];

    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['root', 'superadmin', 'admin'], true)) {
            return response()->json(['message' => 'Hanya admin yang dapat mengatur approval workflow'], 403);
        }
        if (! $user->org_id) {
            return response()->json(['message' => 'Tenant tidak punya organisasi'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($denial = $this->ensureAdmin($request)) {
            return $denial;
        }

        $orgId = $request->user()->org_id;
        $configs = ApprovalWorkflowConfig::where('org_id', $orgId)->get()->keyBy('module');

        $rows = [];
        foreach (self::SUPPORTED_MODULES as $key => $label) {
            $cfg = $configs[$key] ?? null;
            $rows[] = [
                'module' => $key,
                'module_label' => $label,
                'enabled' => $cfg?->enabled ?? false,
                'steps' => $cfg?->steps ?? [],
                'notes' => $cfg?->notes,
                'updated_at' => $cfg?->updated_at,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $module): JsonResponse
    {
        if ($denial = $this->ensureAdmin($request)) {
            return $denial;
        }
        if (! isset(self::SUPPORTED_MODULES[$module])) {
            return response()->json(['message' => "Module {$module} tidak didukung"], 422);
        }

        $cfg = ApprovalWorkflowConfig::where('org_id', $request->user()->org_id)
            ->where('module', $module)
            ->first();

        return response()->json([
            'data' => [
                'module' => $module,
                'module_label' => self::SUPPORTED_MODULES[$module],
                'enabled' => $cfg?->enabled ?? false,
                'steps' => $cfg?->steps ?? [],
                'notes' => $cfg?->notes,
            ],
        ]);
    }

    public function update(Request $request, string $module): JsonResponse
    {
        if ($denial = $this->ensureAdmin($request)) {
            return $denial;
        }
        if (! isset(self::SUPPORTED_MODULES[$module])) {
            return response()->json(['message' => "Module {$module} tidak didukung"], 422);
        }

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'steps' => 'nullable|array',
            'steps.*.tenant_role_id' => 'required_with:steps|uuid|exists:tenant_roles,id',
            'steps.*.label' => 'nullable|string|max:128',
            'notes' => 'nullable|string|max:1024',
        ]);

        $orgId = $request->user()->org_id;

        // Validate every step's tenant_role belongs to this org
        foreach ($data['steps'] ?? [] as $i => $step) {
            $role = TenantRole::find($step['tenant_role_id']);
            if (! $role || $role->org_id !== $orgId) {
                return response()->json([
                    'message' => "Step #{$i}: tenant_role tidak ditemukan / bukan bagian org",
                ], 422);
            }
        }

        if (($data['enabled'] ?? false) && empty($data['steps'])) {
            return response()->json(['message' => 'Workflow di-enable tapi tidak ada step. Tambah minimal 1 step atau matikan workflow.'], 422);
        }

        $cfg = ApprovalWorkflowConfig::updateOrCreate(
            ['org_id' => $orgId, 'module' => $module],
            [
                'enabled' => $data['enabled'],
                'steps' => $data['steps'] ?? [],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json([
            'message' => 'Konfigurasi approval workflow tersimpan',
            'data' => $cfg,
        ]);
    }

    /**
     * Daftar tenant_roles yang punya permission `<module>:approve` —
     * dipakai dropdown step builder supaya admin gak bisa pilih role yang
     * gak punya kapabilitas approve.
     */
    public function eligibleRoles(Request $request, string $module): JsonResponse
    {
        if ($denial = $this->ensureAdmin($request)) {
            return $denial;
        }
        if (! isset(self::SUPPORTED_MODULES[$module])) {
            return response()->json(['message' => "Module {$module} tidak didukung"], 422);
        }

        $approveKey = "{$module}:approve";
        $roles = TenantRole::where('org_id', $request->user()->org_id)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_system', 'permissions']);

        $eligible = $roles->filter(function ($r) use ($approveKey) {
            $perms = is_array($r->permissions) ? $r->permissions : [];

            // System role yang umumnya jadi approver (admin/dpo) implisit
            // eligible — konsisten dengan ApprovalController gate yang
            // skip permission check untuk system role (backward-compat).
            // Custom role harus explicit punya `<module>:approve` atau wildcard.
            if ($r->is_system && in_array(strtolower($r->name), ['admin', 'dpo', 'manager', 'supervisor'], true)) {
                return true;
            }

            return in_array('*', $perms, true) || in_array($approveKey, $perms, true);
        })->values();

        return response()->json([
            'data' => $eligible,
            'all_roles' => $roles, // termasuk yg gak eligible (untuk warning UI)
        ]);
    }
}
