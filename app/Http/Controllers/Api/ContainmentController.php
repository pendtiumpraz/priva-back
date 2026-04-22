<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use App\Models\ContainmentTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Breach containment workflow — templates + checklist state + RACI.
 *
 * Endpoints:
 *   GET    /breach/{id}/containment          → current checklist state
 *   GET    /containment-templates            → list available templates
 *   POST   /containment-templates            → tenant creates custom template
 *   PUT    /containment-templates/{id}       → edit tenant template
 *   POST   /breach/{id}/apply-template       → seed checklist from template
 *   PUT    /breach/{id}/containment/{step}   → update single step
 *                                              (done / notes / assignee / evidence)
 *   GET    /raci-matrix                      → tenant default RACI matrix
 *   PUT    /raci-matrix                      → save tenant default RACI
 */
class ContainmentController extends Controller
{
    public function listTemplates(Request $request)
    {
        $user = $request->user();
        $rows = ContainmentTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->orderBy('case_type')
            ->orderByDesc('is_default')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function createTemplate(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'case_type' => 'required|string|max:40',
            'label' => 'required|string|max:150',
            'description' => 'nullable|string',
            'steps' => 'required|array|min:1',
            'steps.*.key' => 'required|string|max:80',
            'steps.*.label' => 'required|string|max:255',
            'steps.*.category' => 'nullable|string|max:40',
            'steps.*.requires_evidence' => 'nullable|boolean',
            'steps.*.hint' => 'nullable|string|max:500',
        ]);
        $row = ContainmentTemplate::create([
            ...$data,
            'org_id' => $user->org_id,
            'is_system' => false,
            'is_default' => false,
            'created_by' => $user->id,
        ]);
        return response()->json(['data' => $row], 201);
    }

    public function updateTemplate(Request $request, string $id)
    {
        $user = $request->user();

        // Look up the target. Tenant-owned → edit in place. System (org_id=null)
        // → auto-fork to tenant copy (copy-on-write), keeping UX as "just edit".
        $tpl = ContainmentTemplate::where(function ($q) use ($user) {
            $q->where('org_id', $user->org_id)->orWhereNull('org_id');
        })->findOrFail($id);

        $payload = $request->only(['label', 'description', 'steps']);

        if ($tpl->is_system) {
            // Root/superadmin without org_id can edit system templates directly
            // (they own the platform defaults). Tenant users get an auto-fork.
            if (in_array($user->role, ['root', 'superadmin'], true) && !$user->org_id) {
                $tpl->update($payload);
                return response()->json(['data' => $tpl]);
            }

            $fork = ContainmentTemplate::create([
                'org_id' => $user->org_id,
                'case_type' => $tpl->case_type,
                'label' => $payload['label'] ?? $tpl->label,
                'description' => array_key_exists('description', $payload) ? $payload['description'] : $tpl->description,
                'steps' => $payload['steps'] ?? $tpl->steps,
                'is_system' => false,
                'is_default' => false,
                'created_by' => $user->id,
            ]);
            return response()->json(['data' => $fork, 'forked_from' => $tpl->id]);
        }

        $tpl->update($payload);
        return response()->json(['data' => $tpl]);
    }

    public function deleteTemplate(Request $request, string $id)
    {
        $user = $request->user();

        // Platform admins (root/superadmin at no-org scope) may delete system templates;
        // tenants only see their own scope.
        $q = ContainmentTemplate::query();
        $isPlatform = in_array($user->role, ['root', 'superadmin'], true) && !$user->org_id;
        if ($isPlatform) {
            $q->whereNull('org_id');
        } else {
            $q->where('org_id', $user->org_id);
        }
        $tpl = $q->findOrFail($id);

        if ($tpl->is_system && !$isPlatform) {
            return response()->json([
                'message' => 'Template sistem adalah default bawaan platform yang dibagikan ke semua tenant — tidak dapat dihapus per-tenant. Klik "Edit" untuk membuat salinan yang bisa Anda sesuaikan atau hapus.',
            ], 422);
        }

        // Block soft-delete if any active breach still uses it (tenant scope or platform scope).
        $usageQuery = \App\Models\BreachIncident::where('containment_template_id', $tpl->id)
            ->whereNull('deleted_at');
        if (!$isPlatform) {
            $usageQuery->where('org_id', $user->org_id);
        }
        $inUseCount = $usageQuery->count();
        if ($inUseCount > 0) {
            return response()->json([
                'message' => "Tidak bisa dihapus — masih dipakai oleh {$inUseCount} breach aktif. Ganti template di breach tersebut dulu.",
                'in_use' => $inUseCount,
            ], 422);
        }

        $tpl->delete();
        return response()->json(['message' => 'Template dipindahkan ke trash']);
    }

    /** List trashed templates (tenant-owned only). */
    public function listTrashed(Request $request)
    {
        $user = $request->user();
        $rows = ContainmentTemplate::onlyTrashed()
            ->where('org_id', $user->org_id)
            ->orderByDesc('deleted_at')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function restoreTemplate(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = ContainmentTemplate::withTrashed()->where('org_id', $user->org_id)->findOrFail($id);
        $tpl->restore();
        return response()->json(['data' => $tpl, 'message' => 'Template dipulihkan']);
    }

    public function forceDeleteTemplate(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = ContainmentTemplate::withTrashed()->where('org_id', $user->org_id)->findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Template sistem tidak bisa di-hard-delete.'], 422);
        }
        $tpl->forceDelete();
        return response()->json(['message' => 'Template dihapus permanen']);
    }

    /** Apply a template to a breach — seeds containment_checklist. */
    public function applyTemplate(Request $request, string $breachId)
    {
        $user = $request->user();
        $data = $request->validate(['template_id' => 'required|uuid']);
        $breach = BreachIncident::where('org_id', $user->org_id)->findOrFail($breachId);
        $tpl = ContainmentTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($data['template_id']);

        $org = Organization::find($user->org_id);
        $tenantRaci = $org?->settings['raci_matrix'] ?? null;

        $breach->update([
            'containment_template_id' => $tpl->id,
            'case_type' => $tpl->case_type,
            'containment_checklist' => $tpl->buildChecklistState(is_array($tenantRaci) ? $tenantRaci : null),
        ]);
        $tpl->increment('usage_count');

        return response()->json(['data' => $breach->fresh()]);
    }

    /** Update a single containment step. Fires notification to assignee if changed. */
    public function updateStep(Request $request, string $breachId, string $stepKey)
    {
        $user = $request->user();
        $breach = BreachIncident::where('org_id', $user->org_id)->findOrFail($breachId);

        $data = $request->validate([
            'done' => 'nullable|boolean',
            'skipped' => 'nullable|boolean',
            'skipped_reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'evidence_files' => 'nullable|array',
            'evidence_files.*' => 'string',
            'assignee_user_id' => 'nullable|uuid',
            'assignee_group' => 'nullable|string|max:60',
            'raci' => 'nullable|array',
        ]);

        $checklist = $breach->containment_checklist ?? [];
        if (!isset($checklist[$stepKey])) {
            return response()->json(['message' => "Step '{$stepKey}' tidak ditemukan di checklist."], 404);
        }

        $step = $checklist[$stepKey];
        $prevAssignee = $step['assignee_user_id'] ?? null;

        foreach (['done', 'skipped', 'skipped_reason', 'notes', 'evidence_files', 'assignee_user_id', 'assignee_group', 'raci'] as $k) {
            if (array_key_exists($k, $data)) {
                $step[$k] = $data[$k];
            }
        }
        // Mark as skipped — track who + when + reason. Skipping auto-unsets `done`.
        if (!empty($data['skipped'])) {
            $step['done'] = false;
            $step['skipped'] = true;
            $step['skipped_by'] = $user->id;
            $step['skipped_at'] = now()->toIso8601String();
            $step['completed_by'] = null;
            $step['completed_at'] = null;
        } elseif (isset($data['skipped']) && !$data['skipped']) {
            $step['skipped'] = false;
            $step['skipped_by'] = null;
            $step['skipped_at'] = null;
            $step['skipped_reason'] = null;
        }
        if (!empty($data['done']) && empty($step['completed_by'])) {
            $step['skipped'] = false; // mutually exclusive
            $step['completed_by'] = $user->id;
            $step['completed_at'] = now()->toIso8601String();
        } elseif (isset($data['done']) && !$data['done']) {
            $step['completed_by'] = null;
            $step['completed_at'] = null;
        }

        $checklist[$stepKey] = $step;
        $breach->update(['containment_checklist' => $checklist]);

        // Notify new assignee if changed.
        if (isset($data['assignee_user_id']) && $data['assignee_user_id'] && $data['assignee_user_id'] !== $prevAssignee) {
            try {
                NotificationService::dispatch(
                    kind: 'alert',
                    severity: 'high',
                    module: 'breach',
                    type: 'breach.containment.assigned',
                    recipient: 'user:' . $data['assignee_user_id'],
                    orgId: $breach->org_id,
                    title: "📋 Anda ditugaskan: {$step['label']}",
                    body: "Breach {$breach->incident_code} — langkah containment perlu Anda eksekusi.",
                    actionUrl: "/breach/{$breach->id}",
                    metadata: ['record_id' => $breach->id, 'step_key' => $stepKey]
                );
            } catch (\Throwable $e) { \Log::warning('Containment assignment notif failed: ' . $e->getMessage()); }
        }

        // Notify RACI "consulted" + "informed" on completion.
        if (!empty($data['done'])) {
            try {
                $raci = $step['raci'] ?? [];
                $targets = array_merge(
                    (array) ($raci['accountable'] ?? []),
                    (array) ($raci['informed'] ?? [])
                );
                foreach (array_unique($targets) as $roleTarget) {
                    if (!$roleTarget || $roleTarget === 'all-staff') continue;
                    NotificationService::dispatch(
                        kind: 'info',
                        severity: 'low',
                        module: 'breach',
                        type: 'breach.containment.completed',
                        recipient: 'role:' . $roleTarget,
                        orgId: $breach->org_id,
                        title: "✅ Step selesai: {$step['label']}",
                        body: "Breach {$breach->incident_code}",
                        actionUrl: "/breach/{$breach->id}",
                        metadata: ['record_id' => $breach->id, 'step_key' => $stepKey]
                    );
                }
            } catch (\Throwable $e) { /* swallow */ }
        }

        return response()->json(['data' => $step]);
    }

    /**
     * Add a custom step to a breach's containment checklist.
     * Scope: per-breach only — tenant templates stay untouched.
     * RACI auto-assigned based on the step's category (or "general").
     */
    public function addStep(Request $request, string $breachId)
    {
        $user = $request->user();
        if (!$this->canEditContainment($user)) {
            return response()->json(['message' => 'Hanya DPO/admin yang boleh mengubah checklist.'], 403);
        }
        $breach = BreachIncident::where('org_id', $user->org_id)->findOrFail($breachId);

        $data = $request->validate([
            'label' => 'required|string|max:255',
            'category' => 'nullable|string|max:40',
            'requires_evidence' => 'nullable|boolean',
            'hint' => 'nullable|string|max:500',
        ]);

        $category = $data['category'] ?? 'general';
        $defaultRaci = ContainmentTemplate::CATEGORY_RACI[$category] ?? ContainmentTemplate::CATEGORY_RACI['general'];
        $key = 'custom_' . substr(md5($data['label'] . microtime()), 0, 8);

        $step = [
            'label' => $data['label'],
            'category' => $category,
            'requires_evidence' => (bool) ($data['requires_evidence'] ?? false),
            'hint' => $data['hint'] ?? null,
            'raci' => $defaultRaci,
            'assignee_user_id' => null,
            'assignee_group' => null,
            'done' => false,
            'notes' => null,
            'evidence_files' => [],
            'completed_by' => null,
            'completed_at' => null,
            'is_custom' => true,  // flag so UI can show "Custom step" + delete button
            'added_by' => $user->id,
            'added_at' => now()->toIso8601String(),
        ];

        $checklist = $breach->containment_checklist ?? [];
        $checklist[$key] = $step;
        $breach->update(['containment_checklist' => $checklist]);

        return response()->json(['data' => ['key' => $key, 'step' => $step]]);
    }

    /**
     * Remove a step from the breach checklist. Blocks removal of
     * already-completed steps (audit trail preservation).
     */
    public function removeStep(Request $request, string $breachId, string $stepKey)
    {
        $user = $request->user();
        if (!$this->canEditContainment($user)) {
            return response()->json(['message' => 'Hanya DPO/admin yang boleh mengubah checklist.'], 403);
        }
        $breach = BreachIncident::where('org_id', $user->org_id)->findOrFail($breachId);

        $checklist = $breach->containment_checklist ?? [];
        if (!isset($checklist[$stepKey])) {
            return response()->json(['message' => "Step '{$stepKey}' tidak ditemukan."], 404);
        }
        $step = $checklist[$stepKey];
        if (is_array($step) && !empty($step['done'])) {
            return response()->json(['message' => 'Step yang sudah ditandai selesai tidak bisa dihapus (audit trail).'], 422);
        }

        unset($checklist[$stepKey]);
        $breach->update(['containment_checklist' => $checklist]);

        return response()->json(['message' => 'Step dihapus', 'key' => $stepKey]);
    }

    private function canEditContainment($user): bool
    {
        if (in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true)) return true;
        $tenantRole = optional($user->tenantRole)->name ?? '';
        return str_contains(strtolower($tenantRole), 'dpo');
    }

    /** Tenant-level default RACI matrix (stored in organization.settings). */
    public function getRaciMatrix(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);
        $matrix = $org->settings['raci_matrix'] ?? ContainmentTemplate::CATEGORY_RACI;
        return response()->json([
            'data' => $matrix,
            'default' => ContainmentTemplate::CATEGORY_RACI,
        ]);
    }

    public function updateRaciMatrix(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['root', 'superadmin', 'admin', 'dpo'], true)) {
            return response()->json(['message' => 'Hanya admin/DPO yang boleh edit RACI matrix.'], 403);
        }
        $data = $request->validate([
            'matrix' => 'required|array',
            'matrix.*.responsible' => 'nullable|string|max:60',
            'matrix.*.accountable' => 'nullable|string|max:60',
            'matrix.*.consulted' => 'nullable|array',
            'matrix.*.informed' => 'nullable|array',
        ]);
        $org = Organization::findOrFail($user->org_id);
        $settings = $org->settings ?? [];
        $settings['raci_matrix'] = $data['matrix'];
        $org->settings = $settings;
        $org->save();
        return response()->json(['data' => $data['matrix']]);
    }
}
