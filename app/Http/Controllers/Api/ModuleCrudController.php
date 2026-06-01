<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\ContainmentTemplate;
use App\Models\Dpia;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\InformationSystem;
use App\Models\ModuleCustomField;
use App\Models\Organization;
use App\Models\ProcessingCategory;
use App\Models\Ropa;
use App\Services\ApprovalWorkflowDispatcher;
use App\Services\AssessmentAutoTriggerService;
use App\Services\NotificationService;
use App\Services\RopaRiskCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return null;
        }

        if (! $user->relationLoaded('tenantRole')) {
            $user->load('tenantRole');
        }

        $permissions = $user->tenantRole?->permissions ?? null;
        $moduleId = $this->permissionModuleId($module);

        if (! is_array($permissions)) {
            // Legacy fallback
            if ($action === 'write' && ! in_array($user->role, ['admin', 'dpo', 'maker'])) {
                return response()->json(['message' => 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.'], 403);
            }

            return null;
        }

        if (in_array('*', $permissions)) {
            return null;
        }

        if ($action === 'write') {
            if (! in_array("{$moduleId}:write", $permissions)) {
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
    /**
     * Resolve division code untuk RoPA registration number prefix.
     * Sumber prioritas:
     *   1. wizard_data.detail_pemrosesan.divisi_penanggung_jawab → cari
     *      Department by name + ambil .code (mis. "HR Division" → "HR").
     *   2. wizard_data.detail_pemrosesan.divisi (legacy single).
     *   3. column `division` di payload (legacy lebih lama).
     * Return null kalau tidak ada Department yang match (atau code-nya kosong),
     * sehingga nextCode fallback ke legacy ROPA-YYYY-NNN.
     */
    private function resolveDivisionCodeForRopa(array $data, ?string $orgId): ?string
    {
        if (! $orgId) return null;
        $wiz = $data['wizard_data'] ?? null;
        $wiz = is_array($wiz) ? $wiz : (is_string($wiz) ? (json_decode($wiz, true) ?: []) : []);
        $detail = $wiz['detail_pemrosesan'] ?? [];
        $candidates = [
            $detail['divisi_penanggung_jawab'] ?? null,
            $detail['divisi'] ?? null,
            $data['division'] ?? null,
        ];
        foreach ($candidates as $name) {
            if (! is_string($name) || trim($name) === '') continue;
            $dept = \App\Models\Department::where('org_id', $orgId)
                ->where('name', $name)
                ->first();
            if ($dept && ! empty($dept->code)) {
                return (string) $dept->code;
            }
        }
        return null;
    }

    private function nextCode(string $prefix, $model, string $orgId, ?string $categoryId = null, ?string $customNumber = null, ?string $divisionCode = null): string
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

                return implode('-', array_filter($segments, fn ($s) => $s !== ''));
            }
        }

        // Division-code-based numbering (RoPA modal sekarang nge-skip
        // ProcessingCategory dan langsung pakai kode divisi penanggung
        // jawab — mis. "HR" → ROPA-HR-2026-001). Counter per (prefix,
        // divcode, year) di-derived dari max+1 pattern.
        $cleanDiv = $divisionCode ? preg_replace('/[^A-Za-z0-9]/', '', strtoupper($divisionCode)) : null;
        if ($cleanDiv) {
            $codeColumn = match ($prefix) {
                'ROPA', 'DPIA' => 'registration_number',
                'DSR' => 'request_id',
                'CNT' => 'collection_id',
                'BRC' => 'incident_code',
                default => 'registration_number',
            };
            $pattern = $prefix.'-'.$cleanDiv.'-'.$year.'-%';
            $codes = $model->withTrashed()
                ->where('org_id', $orgId)
                ->where($codeColumn, 'like', $pattern)
                ->pluck($codeColumn)
                ->toArray();
            $maxNum = 0;
            foreach ($codes as $code) {
                $num = (int) substr($code, strrpos($code, '-') + 1);
                if ($num > $maxNum) $maxNum = $num;
            }
            $next = $maxNum + 1;
            $segments = [$prefix, $cleanDiv, $year];
            if ($customNumber !== null && $customNumber !== '') {
                $segments[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($customNumber));
            }
            $segments[] = str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            return implode('-', array_filter($segments, fn ($s) => $s !== ''));
        }

        // Legacy fallback — no category, no division code
        $pattern = $prefix.'-'.$year.'-%';

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
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }

        return $prefix.'-'.$year.'-'.str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
    }

    /**
     * RoPA per-user/divisi access scope. Non-admin/non-DPO hanya lihat RoPA:
     *   (a) assign_group='(All Group)' atau null — terbuka untuk semua user
     *       di tenant yang sama (tenant boundary tetap via org_id),
     *   (b) user.id ada di kolom JSON assignees,
     *   (c) user.department.name ada di
     *       wizard_data.detail_pemrosesan.divisi_list (multi-divisi baru)
     *       ATAU wizard_data.detail_pemrosesan.divisi (single legacy)
     *       ATAU kolom `division` lama,
     *   (d) creator (created_by = user.id) — pembuat selalu bisa lihat.
     *
     * Admin/superadmin/DPO bypass (terlihat semua di tenant). Tenant boundary
     * tetap dijaga oleh getQuery() — query ini hanya menambah WHERE, tidak
     * pernah melonggarkan org_id constraint.
     */
    private function applyRopaUserScope($query, Request $request, string $module): void
    {
        if ($module !== 'ropa') return;
        $user = $request->user();
        if (! $user) return;
        $role = $user->role ?? '';
        $tenantRoleName = optional($user->tenantRole)->name;
        $isAdminish = in_array($role, ['superadmin', 'admin', 'dpo'], true)
            || in_array(strtolower((string) $tenantRoleName), ['admin', 'dpo'], true);
        if ($isAdminish) return;

        $userId = $user->id;
        $deptName = optional($user->department)->name;

        $query->where(function ($w) use ($userId, $deptName) {
            // (a) All-group
            $w->where(function ($a) {
                $a->whereNull('assign_group')
                  ->orWhere('assign_group', '(All Group)');
            });
            // (b) Explicit assignee
            $w->orWhereJsonContains('assignees', $userId);
            // (d) Creator
            $w->orWhere('created_by', $userId);
            // (c) Divisi user terlibat di RoPA
            if ($deptName) {
                $w->orWhereJsonContains('wizard_data->detail_pemrosesan->divisi_list', $deptName);
                $w->orWhere('wizard_data->detail_pemrosesan->divisi', $deptName);
                $w->orWhere('division', $deptName);
            }
        });
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
     * Recompute RoPA risk_level from 7-step wizard triggers (Sprint E1).
     * Writes `wizard_data.risk_triggers` for audit trail and returns the
     * patched data array (caller reassigns).
     *
     * Honors risk_level_locked — if true, user-set level is preserved but
     * the triggers/reasons are still recorded for transparency.
     */
    private function applyRopaAutoRisk(array $data): array
    {
        try {
            $wizard = $data['wizard_data'] ?? [];
            if (! is_array($wizard)) {
                $wizard = [];
            }

            $result = app(RopaRiskCalculator::class)->calculate($wizard);

            $wizard['risk_triggers'] = [
                'level' => $result['level'],
                'triggers' => $result['triggers'],
                'reasons' => $result['reasons'],
                'computed_at' => now()->toIso8601String(),
            ];
            $data['wizard_data'] = $wizard;

            $locked = filter_var($data['risk_level_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $locked) {
                $data['risk_level'] = $result['level'];
            }
        } catch (\Throwable $e) {
            // Calculator failure must never block the save — keep whatever
            // risk_level the caller submitted (or leave unchanged).
            \Log::warning('applyRopaAutoRisk failed, leaving risk_level untouched: '.$e->getMessage());
        }

        // Defensive: drop risk_level_locked if the column doesn't exist yet
        // on the deployed DB (migration 2026_04_22_000005 not run). Mass-
        // assigning an unknown column bubbles a SQL error that surfaces as
        // "save ropa gagal".
        if (array_key_exists('risk_level_locked', $data)) {
            try {
                if (! Schema::hasColumn('ropas', 'risk_level_locked')) {
                    unset($data['risk_level_locked']);
                }
            } catch (\Throwable $e) {
                // If even the schema check fails, strip the field to stay safe.
                unset($data['risk_level_locked']);
            }
        }

        return $data;
    }

    /**
     * Keep `linked_ropa_ids` (array) and legacy `linked_ropa_id` (single FK)
     * in sync so both old queries/views and new multi-select UI work.
     *
     * Resolution rules:
     *  - If linked_ropa_ids is sent → it wins. linked_ropa_id = first element.
     *  - Else if linked_ropa_id is sent → linked_ropa_ids = [that id] (or null).
     *  - Else both untouched.
     *
     * Empty arrays / empty strings clear both fields.
     */
    /**
     * Sync DPIA's dpia_ropa pivot from wizard_data.koneksi_ropa.connected_ropas.
     * Idempotent — replaces (any RoPA removed from wizard is detached too).
     */
    private function syncDpiaRopas($dpia): void
    {
        $wizard = $dpia->wizard_data ?? [];
        $section = $wizard['koneksi_ropa'] ?? [];
        $ids = array_filter(array_unique($section['connected_ropas'] ?? []));
        if (! is_array($ids)) {
            return;
        }

        $valid = Ropa::whereIn('id', $ids)
            ->where('org_id', $dpia->org_id)
            ->pluck('id')->all();

        // Include legacy single ropa_id if set, so it appears in pivot too
        if ($dpia->ropa_id && ! in_array($dpia->ropa_id, $valid, true)) {
            $exists = Ropa::where('id', $dpia->ropa_id)->where('org_id', $dpia->org_id)->exists();
            if ($exists) {
                $valid[] = $dpia->ropa_id;
            }
        }

        $syncData = [];
        foreach ($valid as $id) {
            $syncData[$id] = ['org_id' => $dpia->org_id];
        }
        $dpia->ropas()->sync($syncData);
    }

    /**
     * Sync RoPA's information_system_ropa pivot from wizard_data.detail_pemrosesan.sistem_terkait.
     * Idempotent — sync REPLACES (any system removed from wizard is detached too).
     * No-op if no sistem_terkait array provided.
     */
    private function syncRopaInformationSystems($ropa): void
    {
        $wizard = $ropa->wizard_data ?? [];
        $section = $wizard['detail_pemrosesan'] ?? [];
        $raw = $section['sistem_terkait'] ?? null;
        if ($raw === null) {
            return;
        }

        // Normalize: array of UUIDs OR array of objects {id: ...}
        $ids = collect(is_array($raw) ? $raw : [])
            ->map(fn ($v) => is_array($v) ? ($v['id'] ?? null) : (is_string($v) ? $v : null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Verify all belong to same org
        $valid = InformationSystem::whereIn('id', $ids)
            ->where('org_id', $ropa->org_id)
            ->pluck('id')
            ->all();

        $syncData = [];
        foreach ($valid as $id) {
            $syncData[$id] = ['org_id' => $ropa->org_id];
        }
        $ropa->informationSystems()->sync($syncData);
    }

    private function normalizeBreachRopaLinks(array $data): array
    {
        if (array_key_exists('linked_ropa_ids', $data)) {
            $ids = $data['linked_ropa_ids'];
            if (is_string($ids)) {
                $decoded = json_decode($ids, true);
                $ids = is_array($decoded) ? $decoded : [];
            }
            $ids = is_array($ids) ? array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== '')) : [];
            $data['linked_ropa_ids'] = $ids ?: null;
            $data['linked_ropa_id'] = $ids[0] ?? null;
        } elseif (array_key_exists('linked_ropa_id', $data)) {
            $id = $data['linked_ropa_id'] ?: null;
            $data['linked_ropa_id'] = $id;
            $data['linked_ropa_ids'] = $id ? [$id] : null;
        }

        return $data;
    }

    /**
     * List records
     */
    public function index(Request $request, string $module)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) {
            return $denied;
        }
        $query = $this->getQuery($request, $module);

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }
        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $this->applyRopaUserScope($query, $request, $module);

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

            if (! empty($searchColumns)) {
                $query->where(function ($sub) use ($searchColumns, $q) {
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

        // Eager-load DSR app for list view (used by /dsr page App column + filter)
        if ($module === 'dsr') {
            $query->with(['app:id,name,app_code']);
        }

        $query->orderBy('created_at', 'desc');

        // Untuk modul yang punya kolom JSON besar (scan_results, ai_scan_results,
        // protection_assessments di information_systems), select kolom KECIL saja
        // untuk list view. Detail page (show) tetap fetch full row.
        // Alasan: MySQL shared hosting punya sort_buffer_size sangat kecil
        // (default 256KB); SELECT * + ORDER BY pada row dengan JSON multi-MB
        // memicu error 1038 "Out of sort memory".
        $listColumns = match ($module) {
            'data-discovery' => [
                'id', 'org_id', 'name', 'code', 'description', 'owner', 'owner_id',
                'source_type', 'connection_type',
                'scanning_status', 'scanning_progress',
                'pdp_alert_count', 'pii_alert_count',
                'last_scanned_at', 'is_sharded',
                'created_at', 'updated_at', 'deleted_at',
            ],
            default => ['*'],
        };

        // Pagination
        if ($request->filled('per_page')) {
            $perPage = (int) $request->get('per_page', 25);
            $paginated = $query->cursorPaginate($perPage, $listColumns);

            return response()->json($paginated);
        }

        return response()->json(['data' => $query->get($listColumns)]);
    }

    /**
     * Create record
     */
    public function store(Request $request, string $module)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) {
            return $denied;
        }
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
                // Multi-RoPA linkage: normalize linked_ropa_ids + sync legacy linked_ropa_id.
                $data = $this->normalizeBreachRopaLinks($data);
            }

            // Auto-generate codes
            switch ($module) {
                case 'ropa':
                    // Resolve division code dari divisi penanggung jawab
                    // (wizard_data → divisi_penanggung_jawab → Department.code)
                    // sebagai pengganti kategori pemrosesan.
                    $divCode = $this->resolveDivisionCodeForRopa($data, $orgId = $data['org_id']);
                    $data['registration_number'] = $data['registration_number'] ?? $this->nextCode(
                        'ROPA', $model, $orgId,
                        $data['category_id'] ?? null,
                        $data['custom_number'] ?? null,
                        $divCode
                    );
                    // Auto-risk from 7-step wizard triggers (Sprint E1).
                    $data = $this->applyRopaAutoRisk($data);
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
                    // Apply per-kind preset for fields klien tidak isi (audience/display_mode/frequency)
                    $kind = $data['kind'] ?? ConsentCollectionPoint::KIND_COOKIE;
                    if (! in_array($kind, ConsentCollectionPoint::KINDS, true)) {
                        $kind = ConsentCollectionPoint::KIND_COOKIE;
                    }
                    $data['kind'] = $kind;
                    foreach (ConsentCollectionPoint::presetForKind($kind) as $k => $v) {
                        if (empty($data[$k])) {
                            $data[$k] = $v;
                        }
                    }
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
                        $tpl = ContainmentTemplate::forCase($data['org_id'], $caseType);
                        if ($tpl) {
                            $org = Organization::find($data['org_id']);
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
                            ['time' => now()->format('d/m/Y H:i'), 'event' => '🔴 Insiden terdeteksi — '.($data['source'] ?? 'manual')],
                        ];
                    }
                    break;
            }

            $record = $model->create($data);

            // Sync RoPA ↔ Information System pivot on create
            if ($module === 'ropa') {
                try {
                    $this->syncRopaInformationSystems($record);
                } catch (\Throwable $e) {
                    \Log::warning('syncRopaInformationSystems on create failed: '.$e->getMessage());
                }
            }
            // Sync DPIA ↔ RoPA pivot on create (from wizard.koneksi_ropa.connected_ropas)
            if ($module === 'dpia') {
                try {
                    $this->syncDpiaRopas($record);
                } catch (\Throwable $e) {
                    \Log::warning('syncDpiaRopas on create failed: '.$e->getMessage());
                }
            }

            // Audit log: record created
            try {
                AuditLog::log($module, $record->id, 'created', [
                    'registration_number' => $record->registration_number ?? $record->request_id ?? $record->incident_code ?? null,
                ], 'system');
            } catch (\Exception $e) {
                // Don't fail the main operation if audit logging fails
                \Log::warning('Audit log failed: '.$e->getMessage());
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
                        body: ($record->description ?? 'Data breach detected').' — 72 jam untuk notifikasi.',
                        actionUrl: "/breach/{$record->id}",
                        metadata: ['record_id' => $record->id, 'incident_code' => $record->incident_code]
                    );
                }
                // RoPA with assignees → per-user info notification.
                if ($module === 'ropa' && ! empty($data['assignees']) && is_array($data['assignees'])) {
                    foreach ($data['assignees'] as $uid) {
                        NotificationService::dispatch(
                            kind: 'info',
                            severity: 'low',
                            module: 'ropa',
                            type: 'ropa.assigned',
                            recipient: 'user:'.$uid,
                            orgId: $record->org_id,
                            title: "RoPA {$record->registration_number} di-assign ke Anda",
                            body: $record->processing_activity ?? '',
                            actionUrl: "/ropa/{$record->id}",
                            metadata: ['record_id' => $record->id]
                        );
                    }
                }
            } catch (\Throwable $e) {
                // \Throwable (bukan cuma \Exception) supaya \Error dari
                // provider misconfig (mis. credential AI/SMTP/Telegram) tidak
                // bubble ke outer catch dan menggagalkan create record.
                \Log::warning('Notification dispatch failed on create: '.$e->getMessage());
            }

            // Auto-trigger: if RoPA risk=high → create draft DPIA with inherited wizard_data.
            // Wrapped in try/catch so DPIA-side failures don't roll back RoPA create.
            $autoDpiaId = null;
            if ($module === 'ropa' && ($data['risk_level'] ?? '') === 'high') {
                try {
                    $dpiaModel = $this->getModel('dpia');
                    $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
                    if (! $existingDpia) {
                        // Build DPIA wizard_data from RoPA's wizard_data
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
                            'description' => 'Auto-generated dari RoPA high-risk: '.($data['processing_activity'] ?? ''),
                            'wizard_data' => $dpiaWizardData,
                            'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                            'mitigation_measures' => [],
                            'created_by' => $data['created_by'],
                        ]);
                        $autoDpiaId = $autoDpia->id;

                        // Notify DPO: high-risk RoPA spawned an auto-DPIA.
                        try {
                            NotificationService::dispatch(
                                kind: 'warning',
                                severity: 'high',
                                module: 'dpia',
                                type: 'dpia.auto_created',
                                recipient: 'role:dpo',
                                orgId: $record->org_id,
                                title: "⚠️ DPIA otomatis: {$autoDpia->registration_number}",
                                body: "Dibuat dari RoPA high-risk {$record->registration_number} — review diperlukan.",
                                actionUrl: "/dpia/{$autoDpia->id}",
                                metadata: ['record_id' => $autoDpia->id, 'ropa_id' => $record->id]
                            );
                        } catch (\Exception $e) {
                            \Log::warning('DPIA auto-create notification failed: '.$e->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Auto-DPIA on RoPA store failed (non-fatal): '.$e->getMessage());
                }
            }

            // Auto-trigger: RoPA with legal_basis = legitimate interest → draft LIA.
            // Sprint X4 — wraps in try/catch via the service so failure can't roll back RoPA.
            $autoLiaId = null;
            if ($module === 'ropa') {
                try {
                    $lia = app(AssessmentAutoTriggerService::class)
                        ->fromRopa($record, $data['created_by'] ?? null);
                    $autoLiaId = $lia?->id;
                } catch (\Throwable $e) {
                    \Log::warning('Auto-LIA on RoPA store failed (non-fatal): '.$e->getMessage());
                }
            }

            // Auto-trigger: DSR with app_id → seed scopes from app.default_information_system_ids
            // so DPO doesn't need to manually pick. Tab Scope page langsung pre-populated.
            $autoScopeCount = 0;
            if ($module === 'dsr' && ! empty($record->app_id)) {
                try {
                    $app = DsrApp::where('id', $record->app_id)
                        ->where('org_id', $record->org_id)->first();
                    $defaultIds = $app?->default_information_system_ids ?? [];
                    if (! empty($defaultIds)) {
                        // Validate IS belong to same org (defensive)
                        $validIs = InformationSystem::whereIn('id', $defaultIds)
                            ->where('org_id', $record->org_id)
                            ->get(['id', 'is_sharded', 'shards']);
                        foreach ($validIs as $is) {
                            DsrRequestScope::create([
                                'dsr_request_id' => $record->id,
                                'information_system_id' => $is->id,
                                'request_types' => [$record->request_type],
                                'shards_affected' => $is->is_sharded
                                    ? collect($is->shards ?? [])->map(fn ($s) => is_array($s) ? ($s['name'] ?? null) : $s)->filter()->values()->all()
                                    : [],
                                'sql_pack_status' => 'pending',
                            ]);
                            $autoScopeCount++;
                        }
                        // Auto-bump status pending_review → in_progress kalau scope assigned
                        if ($autoScopeCount > 0 && $record->status === 'pending_review') {
                            $record->update(['status' => 'in_progress']);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Auto-scope on DSR store failed (non-fatal): '.$e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Created',
                'data' => $record,
                'auto_dpia_id' => $autoDpiaId,
                'auto_lia_id' => $autoLiaId ?? null,
                'auto_scope_count' => $autoScopeCount,
            ], 201);
        } catch (\Throwable $e) {
            // Catch \Throwable (bukan cuma \Exception) supaya \Error juga
            // tertangani — jangan biarkan Laravel handler render full message
            // ke client saat APP_DEBUG=true.
            \Log::error('ModuleCrud store error: '.$e->getMessage(), [
                'module' => $module,
                'trace' => $e->getTraceAsString(),
            ]);

            // JANGAN bocorkan raw exception message ke client — pesan dari
            // provider (AI/SMTP/Telegram) sering memuat API key / credential.
            // Detail lengkap sudah masuk ke log server untuk ops follow-up.
            return response()->json([
                'message' => 'Gagal menyimpan data. Silakan coba lagi atau hubungi admin bila berlanjut.',
            ], 500);
        }
    }

    /**
     * Show record
     */
    public function show(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) {
            return $denied;
        }
        $query = $this->getQuery($request, $module)->withTrashed();

        if ($module === 'consent') {
            $query->with(['items', 'records']);
        }
        if ($module === 'dsr') {
            $query->with(['app:id,name,app_code']);
        }

        // Scope filter sama dengan list — supaya user tidak bisa bypass
        // dengan hit /m/ropa/{id} langsung untuk record yang seharusnya
        // tidak boleh ia akses.
        $this->applyRopaUserScope($query, $request, $module);

        $record = $query->findOrFail($id);

        // Sprint C1: attach tenant custom field definitions for RoPA / DPIA
        $customFields = [];
        if (in_array($module, ['ropa', 'dpia'], true)) {
            $customFields = ModuleCustomField::where('org_id', $request->user()->org_id)
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
        if ($denied = $this->checkPermission($request, $module, 'write')) {
            return $denied;
        }
        $query = $this->getQuery($request, $module);
        // Apply scope yang sama dengan index/show — kalau user tidak bisa
        // lihat record-nya, tidak boleh update juga (cegah enumeration ID).
        $this->applyRopaUserScope($query, $request, $module);
        $record = $query->findOrFail($id);

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
                    .($newStatus === 'notification' ? ' · 72 jam untuk notifikasi KOMDIGI + subjek data.' : '');
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
                \Log::warning('Breach status notif failed: '.$e->getMessage());
            }
        }

        // Assign-group lock: for RoPA/DPIA, assignees/assign_group can only
        // change while the record is still in_progress. Waiting/revision/
        // approved records require re-opening (status flip) before reassign.
        if (in_array($module, ['ropa', 'dpia'], true)) {
            $assignFieldsTouched = $request->hasAny(['assignees', 'assign_group']);
            $currentStatus = $record->status ?? 'in_progress';
            $assignEditable = in_array($currentStatus, ['in_progress', 'draft'], true);
            if ($assignFieldsTouched && ! $assignEditable) {
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
        $payload = $request->all();
        if ($module === 'breach') {
            $payload = $this->normalizeBreachRopaLinks($payload);
        }
        if ($module === 'ropa') {
            // Merge current state so calculator sees the union (wizard_data
            // may come partially).
            $merged = array_merge(
                ['wizard_data' => $record->wizard_data, 'risk_level_locked' => $record->risk_level_locked, 'risk_level' => $record->risk_level],
                $payload
            );
            $merged = $this->applyRopaAutoRisk($merged);
            $payload['risk_level'] = $merged['risk_level'];
            $payload['wizard_data'] = $merged['wizard_data'];
        }
        $record->update($payload);

        // Sync RoPA ↔ Information System pivot (many-to-many).
        if ($module === 'ropa') {
            try {
                $this->syncRopaInformationSystems($record);
            } catch (\Throwable $e) {
                \Log::warning("syncRopaInformationSystems failed for RoPA {$record->id}: ".$e->getMessage());
            }
        }
        // Sync DPIA ↔ RoPA pivot
        if ($module === 'dpia') {
            try {
                $this->syncDpiaRopas($record);
            } catch (\Throwable $e) {
                \Log::warning("syncDpiaRopas failed for DPIA {$record->id}: ".$e->getMessage());
            }
        }

        // Notify new assignees added on this update (RoPA/DPIA).
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
                            recipient: 'user:'.$uid,
                            orgId: $record->org_id,
                            title: strtoupper($module)." {$regNum} di-assign ke Anda",
                            body: $activity,
                            actionUrl: "/{$module}/{$record->id}",
                            metadata: ['record_id' => $record->id]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Assignee notification failed: '.$e->getMessage());
        }

        // Approval Workflow trigger if status changes to 'waiting'.
        // Semua module-CRUD support: RoPA, DPIA, Breach, DSR. Admin tenant
        // atur step + role per module di /settings → Approval Workflow.
        if ($request->has('status') && $request->input('status') === 'waiting' && $oldStatus !== 'waiting') {
            if (in_array($module, ['ropa', 'dpia', 'breach', 'dsr'], true)) {
                // Dispatch via shared service supaya konsisten dengan
                // CrossBorder/VendorRisk yang juga pakai mekanisme sama.
                ApprovalWorkflowDispatcher::dispatch(
                    $record->org_id, $module, $record->id
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
                        title: '✋ Approval pending: '.strtoupper($module)." {$record->registration_number}",
                        body: 'Menunggu review DPO untuk '.($record->processing_activity ?? $record->description ?? ''),
                        actionUrl: "/{$module}/{$record->id}",
                        metadata: ['record_id' => $record->id, 'workflow_module' => $module]
                    );
                } catch (\Exception $e) {
                    \Log::warning('Approval pending notification failed: '.$e->getMessage());
                }
            }
        }

        // Audit log: detect section-level changes in wizard_data
        if (! empty($newWizard) && is_array($newWizard)) {
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
                    if (! empty($changedFields)) {
                        AuditLog::log($module, $record->id, 'answer_added', $changedFields, $sectionKey);
                    }
                }
            }
        }

        // Auto-trigger DPIA when RoPA risk changes to high. Wrapped in
        // try/catch — DPIA schema quirks (unique collisions, missing optional
        // columns, soft-deleted twin row, etc.) shouldn't roll back a
        // successful RoPA update and look like "save ropa gagal" to the user.
        if ($module === 'ropa' && ($record->risk_level ?? null) === 'high') {
            try {
                $dpiaModel = $this->getModel('dpia');
                $existingDpia = $dpiaModel->where('ropa_id', $record->id)->first();
                if (! $existingDpia) {
                    $dpiaModel->create([
                        'org_id' => $record->org_id,
                        'category_id' => $record->category_id,
                        'registration_number' => $this->nextCode('DPIA', $dpiaModel, $record->org_id, $record->category_id),
                        'ropa_id' => $record->id,
                        'risk_level' => 'high',
                        'status' => 'draft',
                        'description' => 'Auto-generated dari RoPA high-risk: '.$record->processing_activity,
                        'risk_assessment' => ['likelihood' => 0, 'impact' => 0, 'risks' => []],
                        'mitigation_measures' => [],
                        'created_by' => $request->user()->id,
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Auto-DPIA creation for RoPA '.$record->id.' failed: '.$e->getMessage());
            }
        }

        return response()->json(['message' => 'Updated', 'data' => $record->fresh()]);
    }

    /**
     * Soft delete
     */
    public function destroy(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) {
            return $denied;
        }
        $q = $this->getQuery($request, $module);
        $this->applyRopaUserScope($q, $request, $module);
        $q->findOrFail($id)->delete();

        return response()->json(['message' => 'Moved to trash']);
    }

    /**
     * Restore
     */
    public function restore(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) {
            return $denied;
        }
        $q = $this->getQuery($request, $module)->onlyTrashed();
        $this->applyRopaUserScope($q, $request, $module);
        $record = $q->findOrFail($id);
        $record->restore();

        return response()->json(['message' => 'Restored', 'data' => $record]);
    }

    /**
     * Force delete
     */
    public function forceDelete(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'write')) {
            return $denied;
        }
        $q = $this->getQuery($request, $module)->onlyTrashed();
        $this->applyRopaUserScope($q, $request, $module);
        $q->findOrFail($id)->forceDelete();

        return response()->json(['message' => 'Permanently deleted']);
    }

    /**
     * Get audit history for a record
     */
    public function history(Request $request, string $module, string $id)
    {
        if ($denied = $this->checkPermission($request, $module, 'read')) {
            return $denied;
        }
        // First ensure record belongs to user's org
        $this->getQuery($request, $module)->withTrashed()->findOrFail($id);

        $logs = AuditLog::where('module', $module)
            ->where('record_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $logs]);
    }
}
