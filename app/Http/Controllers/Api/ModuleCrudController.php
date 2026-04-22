<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Ropa, Dpia, DsrRequest, ConsentCollectionPoint, BreachIncident, InformationSystem, AuditLog, ProcessingCategory};
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ModuleCrudController extends Controller
{
    /**
     * Map URL slug to permission module ID.
     */
    private function permissionModuleId(string $module): string
    {
        return match ($module) {
            'data-discovery' => 'data_discovery',
            default => $module,
        };
    }

    /**
     * Check if the current user has permission for the given module + action.
     * Returns null if allowed, or a 403 JsonResponse if denied.
     */
    private function checkPermission(Request $request, string $module, string $action = 'read')
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (in_array($user->role, ['root','superadmin'], true)) return null;

        if (!$user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;
        $moduleId = $this->permissionModuleId($module);

        if (!is_array($permissions)) {
            // Legacy fallback
            if ($action === 'write' && !in_array($user->role, ['admin', 'dpo', 'maker'])) {
                return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.'], 403);
            }
            return null;
        }

        if (in_array('*', $permissions)) return null;

        if ($action === 'write') {
            if (!in_array("{$moduleId}:write", $permissions)) {
                return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.'], 403);
            }
            return null;
        }

        // Read
        if (in_array($moduleId, $permissions) || in_array("{$moduleId}:read", $permissions) || in_array("{$moduleId}:write", $permissions)) {
            return null;
        }

        return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin untuk modul ini.'], 403);
    }

    private function getModel(string $module)
    {
        return match ($module) {
                'ropa' => new Ropa,
                'dpia' => new Dpia,
                'dsr' => new DsrRequest,
                'consent' => new ConsentCollectionPoint,
                'breach' => new BreachIncident,
                'data-discovery' => new InformationSystem,
                default => abort(404, 'Module not found'),
            };
    }

    /**
     * Generate the next registration code.
     *
     * - Legacy format (no category): `ROPA-2026-001` — counter is max+1
     *   across the whole tenant for the prefix+year.
     * - With category: `ROPA-HR-001` — counter is per-category, per-year,
     *   atomically reserved on the ProcessingCategory row.
     * - With category + custom number: `ROPA-HR-PAY-001` — custom_number
     *   is inserted as an extra segment; category counter still advances.
     */
    private function nextCode(string $prefix, $model, string $orgId, ?string $categoryId = null, ?string $customNumber = null): string
    {
        $year = date('Y');

        if ($categoryId) {
            $category = ProcessingCategory::where('org_id', $orgId)->where('id', $categoryId)->first();
            if ($category) {
                $module = in_array($prefix, ['ROPA', 'DPIA'], true) ? strtolower($prefix) : 'ropa';
                $counter = $category->nextCounter($module);
                $segments = [$prefix, strtoupper($category->code)];
                if ($customNumber !== null && $customNumber !== '') {
                    $segments[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($customNumber));
                }
                $segments[] = str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
                return implode('-', array_filter($segments, fn($s) => $s !== ''));
            }
        }

        // Legacy fallback — no category selected
        $pattern = $prefix . '-' . $year . '-%';

        $codeColumn = match ($prefix) {
            'ROPA', 'DPIA' => 'registration_number',
            'DSR' => 'request_id',
            'CNT' => 'collection_id',
            'BRC' => 'incident_code',
            default => 'registration_number',
        };

        $codes = $model->withTrashed()
            ->where($codeColumn, 'like', $pattern)
            ->pluck($codeColumn)
            ->toArray();

        $maxNum = 0;
        foreach ($codes as $code) {
            $num = (int) substr($code, strrpos($code, '-') + 1);
            if ($num > $maxNum) $maxNum = $num;
        }

        return $prefix . '-' . $year . '-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
    }

    private function getQuery(Request $request, string $module)
    {
        $model = $this->getModel($module);
        $query = $model->newQuery();
        
        if ($request->user()->role !== 'superadmin') {
            $query->where('org_id', $request->user()->org_id);
        } elseif ($request->filled('org_id')) {
            $query->where('org_id', $request->org_id);
        }
        
        return $query;
    }

    /**
     * List records
     */
    public function index(Request $request, string $module)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) return $denied;
        $query = $this->getQuery($request, $module);

        if ($request->get('trash'))
            $query->onlyTrashed();
        if ($request->get('status'))
            $query->where('status', $request->get('status'));
            
        // Basic search if 'q' is provided
        if ($request->filled('q')) {
            $q = $request->get('q');
            $searchColumns = match ($module) {
                'ropa' => ['registration_number', 'processing_activity', 'division', 'description'],
                'dpia' => ['registration_number', 'description'],
                'dsr' => ['request_id', 'requester_name', 'description'],
                'consent' => ['collection_id', 'name', 'domain'],
                'breach' => ['incident_code', 'title', 'description'],
                'data-discovery' => ['name', 'connection_type', 'host'],
                default => [],
            };
            
            if (!empty($searchColumns)) {
                $query->where(function($sub) use ($searchColumns, $q) {
                    foreach ($searchColumns as $col) {
                        $sub->orWhere($col, 'like', "%{$q}%");
                    }
                });
            }
        }

        // Add relationship counts for consent module
        if ($module === 'consent') {
            $query->withCount(['items', 'records']);
        }

        $query->orderBy('created_at', 'desc');

        // Pagination
        if ($request->filled('per_page')) {
            $perPage = (int) $request->get('per_page', 25);
            $paginated = $query->cursorPaginate($perPage);
            return response()->json($paginated);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Create record
     */
    public function store(Request $request, string $module)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) return $denied;
        try {
            $model = $this->getModel($module);
            $data = $request->all();
            
            // Allow superadmin to set org_id, otherwise force current user's org_id
            if ($request->user()->role === 'superadmin' && $request->filled('org_id')) {
                $data['org_id'] = $request->org_id;
            } else {
                $data['org_id'] = $request->user()->org_id;
            }

            if (empty($data['org_id'])) {
                return response()->json(['message' => 'Organization ID is required'], 422);
            }

            $data['created_by'] = $request->user()->id;

            // Ensure boolean fields are properly cast
            if ($module === 'breach') {
                $data['notification_required'] = filter_var($data['notification_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['is_simulation'] = filter_var($data['is_simulation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['affected_subjects_count'] = (int) ($data['affected_subjects_count'] ?? 0);
            }

            // Auto-generate codes
            switch ($module) {
                case 'ropa':
                    $data['registration_number'] = $data['registration_number'] ?? $this->nextCode(
                        'ROPA', $model, $data['org_id'],
                        $data['category_id'] ?? null,
                        $data['custom_number'] ?? null
                    );
                    // Auto-risk: if data_categories contain sensitive types → auto HIGH
                    $sensitiveKeywords = ['kesehatan', 'biometrik', 'genetik', 'anak', 'keuangan', 'ras', 'agama', 'orientasi seksual', 'pandangan politik'];
                    $categories = $data['data_categories'] ?? [];
                    if (is_array($categories)) {
                        $categoriesStr = strtolower(implode(' ', $categories));
                        foreach ($sensitiveKeywords as $keyword) {
                            if (str_contains($categoriesStr, $keyword)) {
                                $data['risk_level'] = 'high';
                                break;
                            }
                        }
                    }
                    break;
                case 'dpia':
                    $data['registration_number'] = $data['registration_number'] ?? $this->nextCode(
                        'DPIA', $model, $data['org_id'],
                        $data['category_id'] ?? null,
                        $data['custom_number'] ?? null
                    );
                    break;
                case 'dsr':
                    $data['request_id'] = $data['request_id'] ?? $this->nextCode('DSR', $model, $data['org_id']);
                    $data['deadline_at'] = $data['deadline_at'] ?? now()->addHours(72);
                    break;
                case 'consent':
                    $data['collection_id'] = $data['collection_id'] ?? $this->nextCode('CNT', $model, $data['org_id']);
                    break;
                case 'breach':
                    $data['incident_code'] = $data['incident_code'] ?? $this->nextCode('BRC', $model, $data['org_id']);
                    $data['detected_at'] = $data['detected_at'] ?? now();
                    if ($data['notification_required']) {
                        $data['notification_deadline'] = $data['notification_deadline'] ?? now()->addHours(72);
                    }
                    // Auto-apply case-type containment template if case_type provided.
                    // Falls back to "other" generic template if case_type not set.
                    if (empty($data['containment_checklist'])) {
                        $caseType = $data['case_type'] ?? 'other';
                        $tpl = \App\Models\ContainmentTemplate::forCase($data['org_id'], $caseType);
                        if ($tpl) {
                            $org = \App\Models\Organization::find($data['org_id']);
                            $tenantRaci = is_array($org?->settings['raci_matrix'] ?? null) ? $org->settings['raci_matrix'] : null;
                            $data['containment_checklist'] = $tpl->buildChecklistState($tenantRaci);
                            $data['containment_template_id'] = $tpl->id;
                            $tpl->increment('usage_count');
                        } else {
                            // Legacy fallback (should rarely trigger — seeder covers all case types).
                            $data['containment_checklist'] = [];
                        }
                    }
                    // Auto-init timeline
                    if (empty($data['timeline_log'])) {
                        $data['timeline_log'] = [
                            ['time' => now()->format('d/m/Y H:i'), 'event' => '🔴 Insiden terdeteksi — ' . ($data['source'] ?? 'manual')],
                        ];
                    }
                    break;
            }

            $record = $model->create($data);

            // Audit log: record created
            try {
                AuditLog::log($module, $record->id, 'created', [
                    'registration_number' => $record->registration_number ?? $record->request_id ?? $record->incident_code ?? null,
                ], 'system');
            } catch (\Exception $e) {
                // Don't fail the main operation if audit logging fails
                \Log::warning('Audit log failed: ' . $e->getMessage());
            }

            // ===== Notification hooks on create =====
            try {
                // Breach: any new incident is a critical alert to the DPO team.
                if ($module === 'breach') {
                    NotificationService::dispatch(
                        kind: 'alert',
                        severity: 'critical',
                        module: 'breach',
                        type: 'breach.created',
                        recipient: 'role:dpo',
                        orgId: $record->org_id,
                        title: "🚨 Insiden baru: {$record->incident_code}",
                        body: ($record->description ?? 'Data breach detected') . ' — 72 jam untuk notifikasi.',
                        actionUrl: "/breach/{$record->id}",
                        metadata: ['record_id' => $record->id, 'incident_code' => $record->incident_code]
                    );
                }
                // ROPA with assignees → per-user info notification.
                if ($module === 'ropa' && !empty($data['assignees']) && is_array($data['assignees'])) {
                    foreach ($data['assignees'] as $uid) {
                        NotificationService::dispatch(
                            kind: 'info',
                            severity: 'low',
                            module: 'ropa',
                            type: 'ropa.assigned',
                            recipient: 'user:' . $uid,
                            orgId: $record->org_id,
                            title: "ROPA {$record->registration_number} di-assign ke Anda",
                            body: $record->processing_activity ?? '',
                            actionUrl: "/ropa/{$record->id}",
                            metadata: ['record_id' => $record->id]
                        );
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Notification dispatch failed on create: ' . $e->getMessage());
            }

            // Auto-trigger: if ROPA risk=high → create draft DPIA with inherited wizard_data
            $autoDpiaId = null;
            if ($module === 'ropa' && ($data['risk_level'] ?? '') === 'high') {
                $dpiaModel = $this->getModel('dpia');
                $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
                if (!$existingDpia) {
                    // Build DPIA wizard_data from ROPA's wizard_data
                    $ropaWiz = $data['wizard_data'] ?? [];
                    $dpoTeam = $ropaWiz['dpo_team'] ?? [];
                    $dpiaWizardData = [
                        'informasi_dpia' => [
                            'description' => $data['processing_activity'] ?? '',
                            'pic_name' => $dpoTeam['pic_name'] ?? '',
                            'dpo_name' => $dpoTeam['dpo_name'] ?? '',
                            'dpo_email' => $dpoTeam['dpo_email'] ?? '',
                            'dpo_phone' => $dpoTeam['dpo_phone'] ?? '',
                        ],
                        'koneksi_ropa' => [
                            'connected_ropas' => [$record->id],
                        ],
                        'potensi_risiko' => [],
                    ];

                    $autoDpia = $dpiaModel->create([
                        'org_id' => $data['org_id'],
                        'category_id' => $data['category_id'] ?? null,
                        'registration_number' => $this->nextCode('DPIA', $dpiaModel, $data['org_id'], $data['category_id'] ?? null),
                        'ropa_id' => $record->id,
                        'risk_level' => 'high',
                        'status' => 'draft',
                        'description' => 'Auto-generated dari ROPA high-risk: ' . ($data['processing_activity'] ?? ''),
                        'wizard_data' => $dpiaWizardData,
                        'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                        'mitigation_measures' => [],
                        'created_by' => $data['created_by'],
                    ]);
                    $autoDpiaId = $autoDpia->id;

                    // Notify DPO: high-risk ROPA spawned an auto-DPIA.
                    try {
                        NotificationService::dispatch(
                            kind: 'warning',
                            severity: 'high',
                            module: 'dpia',
                            type: 'dpia.auto_created',
                            recipient: 'role:dpo',
                            orgId: $record->org_id,
                            title: "⚠️ DPIA otomatis: {$autoDpia->registration_number}",
                            body: "Dibuat dari ROPA high-risk {$record->registration_number} — review diperlukan.",
                            actionUrl: "/dpia/{$autoDpia->id}",
                            metadata: ['record_id' => $autoDpia->id, 'ropa_id' => $record->id]
                        );
                    } catch (\Exception $e) {
                        \Log::warning('DPIA auto-create notification failed: ' . $e->getMessage());
                    }
                }
            }

            return response()->json([
                'message' => 'Created',
                'data' => $record,
                'auto_dpia_id' => $autoDpiaId,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('ModuleCrud store error: ' . $e->getMessage(), [
                'module' => $module,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show record
     */
    public function show(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) return $denied;
        $query = $this->getQuery($request, $module)->withTrashed();

        if ($module === 'consent') {
            $query->with(['items', 'records']);
        }

        $record = $query->findOrFail($id);

        // Sprint C1: attach tenant custom field definitions for ROPA / DPIA
        $customFields = [];
        if (in_array($module, ['ropa', 'dpia'], true)) {
            $customFields = \App\Models\ModuleCustomField::where('org_id', $request->user()->org_id)
                ->forModule($module)
                ->active()
                ->orderBy('sort_order')
                ->get();
        }

        return response()->json([
            'data' => $record,
            'custom_fields' => $customFields,
        ]);
    }

    /**
     * Update record
     */
    public function update(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) return $denied;
        $record = $this->getQuery($request, $module)->findOrFail($id);

        // Auto-append timeline + fire notifications for breach status changes.
        if ($module === 'breach' && $request->has('status') && $record->status !== $request->input('status')) {
            $statusLabels = [
                'detected' => '🔴 Insiden terdeteksi',
                'assessing' => '📋 Assessment dimulai',
                'containment' => '🛡️ Containment dimulai',
                'notification' => '📨 Fase notifikasi dimulai',
                'closed' => '✅ Insiden ditutup',
            ];
            $newStatus = $request->input('status');
            $oldBreachStatus = $record->status;
            $timeline = $record->timeline_log ?? [];
            $timeline[] = [
                'time' => now()->format('d/m/Y H:i'),
                'event' => $statusLabels[$newStatus] ?? "Status → {$newStatus}",
            ];
            $request->merge(['timeline_log' => $timeline]);

            // Notify DPO + incident commanders on every transition.
            // Fase notifikasi = URGENT (72h deadline mulai berjalan).
            try {
                $kind = $newStatus === 'notification' ? 'alert' : ($newStatus === 'closed' ? 'info' : 'warning');
                $severity = $newStatus === 'notification' ? 'critical' : ($newStatus === 'closed' ? 'low' : 'high');
                $body = ($statusLabels[$newStatus] ?? "Status → {$newStatus}")
                    . ($newStatus === 'notification' ? ' · 72 jam untuk notifikasi KOMDIGI + subjek data.' : '');
                NotificationService::dispatch(
                    kind: $kind,
                    severity: $severity,
                    module: 'breach',
                    type: "breach.status.{$newStatus}",
                    recipient: 'role:dpo',
                    orgId: $record->org_id,
                    title: "Breach {$record->incident_code}: {$oldBreachStatus} → {$newStatus}",
                    body: $body,
                    actionUrl: "/breach/{$record->id}",
                    metadata: [
                        'record_id' => $record->id,
                        'incident_code' => $record->incident_code,
                        'old_status' => $oldBreachStatus,
                        'new_status' => $newStatus,
                    ]
                );
            } catch (\Throwable $e) {
                \Log::warning('Breach status notif failed: ' . $e->getMessage());
            }
        }

        // Assign-group lock: for ROPA/DPIA, assignees/assign_group can only
        // change while the record is still in_progress. Waiting/revision/
        // approved records require re-opening (status flip) before reassign.
        if (in_array($module, ['ropa', 'dpia'], true)) {
            $assignFieldsTouched = $request->hasAny(['assignees', 'assign_group']);
            $currentStatus = $record->status ?? 'in_progress';
            $assignEditable = in_array($currentStatus, ['in_progress', 'draft'], true);
            if ($assignFieldsTouched && !$assignEditable) {
                return response()->json([
                    'message' => 'Assign group terkunci karena status bukan in_progress.',
                    'status' => $currentStatus,
                ], 409);
            }
        }

        // Detect wizard_data changes for audit logging
        $oldWizard = $record->wizard_data ?? [];
        $newWizard = $request->input('wizard_data', []);
        $oldStatus = $record->status;
        $oldAssignees = $record->assignees ?? [];
        $record->update($request->all());

        // Notify new assignees added on this update (ROPA/DPIA).
        try {
            if (in_array($module, ['ropa', 'dpia'], true) && $request->has('assignees')) {
                $newAssignees = $request->input('assignees', []);
                if (is_array($newAssignees)) {
                    $added = array_values(array_diff($newAssignees, is_array($oldAssignees) ? $oldAssignees : []));
                    foreach ($added as $uid) {
                        $regNum = $record->registration_number ?? '';
                        $activity = $record->processing_activity ?? $record->description ?? '';
                        NotificationService::dispatch(
                            kind: 'info',
                            severity: 'low',
                            module: $module,
                            type: "{$module}.assigned",
                            recipient: 'user:' . $uid,
                            orgId: $record->org_id,
                            title: strtoupper($module) . " {$regNum} di-assign ke Anda",
                            body: $activity,
                            actionUrl: "/{$module}/{$record->id}",
                            metadata: ['record_id' => $record->id]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Assignee notification failed: ' . $e->getMessage());
        }

        // Approval Workflow trigger if status changes to 'waiting'
        if ($request->has('status') && $request->input('status') === 'waiting' && $oldStatus !== 'waiting') {
            // Need to create workflow based on module
            if (in_array($module, ['ropa', 'dpia'])) {
                $regulationCode = $record->regulation_code ?? 'uupdp';
                // Simplified multi-level DPO -> CEO
                $steps = [
                    ['role' => 'dpo', 'status' => 'pending', 'name' => 'Review DPO'],
                    ['role' => 'admin', 'status' => 'pending', 'name' => 'Final Approval (Management)']
                ];
                
                // For GDPR/PDPA, maybe require an external auditor step. For now standard 2 step.
                
                \App\Models\ApprovalWorkflow::updateOrCreate(
                    ['module' => $module, 'record_id' => $record->id, 'status' => 'pending'],
                    ['org_id' => $record->org_id, 'steps' => $steps, 'current_step' => 0]
                );

                // Notify first step reviewer (DPO role) — step pending approval.
                try {
                    NotificationService::dispatch(
                        kind: 'alert',
                        severity: 'high',
                        module: 'approval',
                        type: 'approval.pending',
                        recipient: 'role:dpo',
                        orgId: $record->org_id,
                        title: "✋ Approval pending: " . strtoupper($module) . " {$record->registration_number}",
                        body: "Menunggu review DPO untuk " . ($record->processing_activity ?? $record->description ?? ''),
                        actionUrl: "/{$module}/{$record->id}",
                        metadata: ['record_id' => $record->id, 'workflow_module' => $module]
                    );
                } catch (\Exception $e) {
                    \Log::warning('Approval pending notification failed: ' . $e->getMessage());
                }
            }
        }

        // Audit log: detect section-level changes in wizard_data
        if (!empty($newWizard) && is_array($newWizard)) {
            foreach ($newWizard as $sectionKey => $sectionData) {
                $oldSection = $oldWizard[$sectionKey] ?? [];
                if (json_encode($oldSection) !== json_encode($sectionData)) {
                    // Find what changed
                    $changedFields = [];
                    if (is_array($sectionData)) {
                        foreach ($sectionData as $field => $value) {
                            $oldVal = $oldSection[$field] ?? null;
                            if (json_encode($oldVal) !== json_encode($value)) {
                                $changedFields[$field] = ['old' => $oldVal, 'new' => $value];
                            }
                        }
                    }
                    if (!empty($changedFields)) {
                        AuditLog::log($module, $record->id, 'answer_added', $changedFields, $sectionKey);
                    }
                }
            }
        }

        // Auto-trigger DPIA when ROPA risk changes to high
        if ($module === 'ropa' && $request->input('risk_level') === 'high') {
            $dpiaModel = $this->getModel('dpia');
            $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
            if (!$existingDpia) {
                $dpiaModel->create([
                    'org_id' => $record->org_id,
                    'category_id' => $record->category_id,
                    'registration_number' => $this->nextCode('DPIA', $dpiaModel, $record->org_id, $record->category_id),
                    'ropa_id' => $record->id,
                    'risk_level' => 'high',
                    'status' => 'draft',
                    'description' => 'Auto-generated dari ROPA high-risk: ' . $record->processing_activity,
                    'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                    'mitigation_measures' => [],
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        return response()->json(['message' => 'Updated', 'data' => $record->fresh()]);
    }

    /**
     * Soft delete
     */
    public function destroy(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) return $denied;
        $this->getQuery($request, $module)->findOrFail($id)->delete();
        return response()->json(['message' => 'Moved to trash']);
    }

    /**
     * Restore
     */
    public function restore(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) return $denied;
        $record = $this->getQuery($request, $module)->onlyTrashed()->findOrFail($id);
        $record->restore();
        return response()->json(['message' => 'Restored', 'data' => $record]);
    }

    /**
     * Force delete
     */
    public function forceDelete(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) return $denied;
        $this->getQuery($request, $module)->onlyTrashed()->findOrFail($id)->forceDelete();
        return response()->json(['message' => 'Permanently deleted']);
    }

    /**
     * Get audit history for a record
     */
    public function history(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) return $denied;
        // First ensure record belongs to user's org
        $this->getQuery($request, $module)->withTrashed()->findOrFail($id);

        $logs = AuditLog::where('module', $module)
            ->where('record_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $logs]);
    }
}
