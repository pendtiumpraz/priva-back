<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\ContainmentTemplate;
use App\Models\Department;
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
use App\Services\EntitlementService;
use App\Services\ModuleWrite\ModuleWriteContext;
use App\Services\ModuleWrite\ModuleWriteRejected;
use App\Services\ModuleWrite\RopaDpiaWriter;
use App\Services\NotificationService;
use App\Services\PermissionService;
use App\Services\RegistrationCodeService;
use App\Support\BreachScope;
use App\Support\InformationSystemScope;
use App\Support\PenugasanDivisi;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ModuleCrudController extends Controller
{
    /**
     * Modul yang punya kolom penugasan sendiri (`assign_group`, `assignees`,
     * `origin_division`) dan karena itu memakai trait AssignmentVisibility apa
     * adanya.
     *
     * Dipakai DUA arah dan harus tetap sama: menyaring bacaan
     * (applyRopaUserScope) DAN memasang divisi pembuat saat membuat (store).
     * Memisahkan kedua daftar itu berarti ada modul yang tersaring tapi tidak
     * pernah ditugaskan — atau sebaliknya.
     *
     * `breach` dan `data-discovery` TIDAK di sini: divisinya diturunkan dari
     * baris yang ditautkannya, bukan kolom sendiri (lihat BreachScope dan
     * InformationSystemScope).
     */
    private const MODUL_BERPENUGASAN = ['ropa', 'dpia', 'dsr', 'consent'];

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

        $moduleId = $this->permissionModuleId($module);

        // Gerbang komersial lebih dulu — cermin dari CheckPermission middleware.
        // Route universal ini tidak melewati middleware itu, jadi tanpa
        // penjagaan di sini modul yang dicabut tetap dapat diakses lewat
        // /api/m/{module}.
        if (! app(EntitlementService::class)->allowsModule($user, $moduleId)) {
            return response()->json([
                'message' => 'Modul ini tidak aktif untuk organisasi Anda. Hubungi administrator platform.',
            ], 403);
        }

        // Decision logic centralized in PermissionService (shared with the
        // CheckPermission middleware so the two can't drift out of sync).
        if (app(PermissionService::class)->allows($user, $moduleId, $action)) {
            return null;
        }

        $message = $action === 'write'
            ? 'Akses ditolak — role Anda tidak memiliki izin write untuk modul ini.'
            : 'Akses ditolak — role Anda tidak memiliki izin untuk modul ini.';

        return response()->json(['message' => $message], 403);
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
                if ($num > $maxNum) {
                    $maxNum = $num;
                }
            }
            $next = $maxNum + 1;
            $segments = [$prefix, $cleanDiv, $year];
            if ($customNumber !== null && $customNumber !== '') {
                $segments[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($customNumber));
            }
            $segments[] = str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            return implode('-', array_filter($segments, fn ($s) => $s !== ''));
        }

        // Legacy fallback — no category, no division code. Counted globally
        // (matching the global-unique constraint) via the shared service.
        return app(RegistrationCodeService::class)->nextGlobal($prefix, get_class($model));
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
        $user = $request->user();
        if (! $user) {
            return;
        }

        // Modul yang punya kolom penugasannya sendiri. Logikanya tinggal di
        // trait AssignmentVisibility (scopeVisibleTo) supaya SATU sumber
        // kebenaran dipakai juga oleh AI Agent + @mention.
        if (in_array($module, self::MODUL_BERPENUGASAN, true)) {
            $query->visibleTo($user);

            return;
        }

        // Insiden dan sistem informasi TIDAK punya kolom penugasan — divisinya
        // DITURUNKAN dari RoPA/pihak ketiga yang ditautkannya. Aturannya tidak
        // bisa dinyatakan lewat scopeVisibleTo atas kolom sendiri, jadi
        // klausanya ditempel langsung ke query builder di bawahnya.
        //
        $orgId = (string) $user->org_id;

        match ($module) {
            'breach' => BreachScope::terapkan($query->getQuery(), $user, $orgId),
            'data-discovery' => InformationSystemScope::terapkan($query->getQuery(), $user, $orgId),
            default => null,
        };
    }

    /**
     * Delimiter multi-divisi pada kolom `assign_group` — HARUS identik dengan
     * konstanta FE `ASSIGN_DIV_DELIM` di AssignScopeModal.tsx.
     */
    public const ASSIGN_DIV_DELIM = ' | ';

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

    /**
     * Sync RoPA's information_system_ropa pivot from wizard_data.detail_pemrosesan.sistem_terkait.
     * Idempotent — sync REPLACES (any system removed from wizard is detached too).
     * No-op if no sistem_terkait array provided.
     */

    /**
     * Sync RoPA ↔ pihak ketiga (pivot `ropa_vendor`) dari wizard bagian
     * Penggunaan & Penyimpanan.
     *
     * Dua sumber, sengaja:
     *   - `vendor_links[]` — {id, role, purpose, data_shared[], contract_ref};
     *     sumber kebenaran baru, menyimpan PERAN per kegiatan.
     *   - `vendor_ids[]`   — daftar UUID polos (wizard lama, impor CSV, isian AI);
     *     perannya 'processor' karena bagian ini memang "pihak yang MEMPROSES".
     * Idempotent: sync() melepas tautan yang dihapus dari wizard. No-op bila
     * kedua kunci tidak ada, supaya update parsial tidak menghapus tautan.
     */

    /**
     * Samakan bentuk `linked_vendor_ids` dengan linked_ropa_ids: terima array
     * atau string JSON, buang nilai kosong, simpan null bila tidak ada isinya.
     * Ini pihak ketiga yang DIPASTIKAN terlibat — dugaan lewat RoPA dihitung
     * terpisah oleh BreachThirdPartyController.
     */
    private function normalizeBreachVendorLinks(array $data): array
    {
        if (! array_key_exists('linked_vendor_ids', $data)) {
            return $data;
        }

        $ids = $data['linked_vendor_ids'];
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }
        $ids = is_array($ids)
            ? array_values(array_unique(array_filter(array_map('strval', $ids), fn ($v) => $v !== '')))
            : [];
        $data['linked_vendor_ids'] = $ids ?: null;

        return $data;
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

        // Titik milik modul subjek (Consent Wali / Consent Aksesibilitas)
        // dikelola dari modulnya sendiri — daftar Consent umum hanya
        // menampilkan titik dewasa. Detail/ubah lewat id tetap bisa.
        if ($module === 'consent') {
            $query->whereNull('owner_module');
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

        // Optional division filter (ropa) — used by the breach RoPA picker's
        // division dropdown. Division may live in the legacy `division`/`work_unit`
        // columns or inside wizard_data.detail_pemrosesan.{divisi,divisi_penanggung_jawab};
        // match either. The wizard_data LIKE is loose but division names are
        // distinctive and this is a picker filter, not a security boundary.
        if ($module === 'ropa' && $request->filled('division')) {
            $div = $request->get('division');
            $query->where(function ($sub) use ($div) {
                $sub->where('division', $div)
                    ->orWhere('work_unit', $div)
                    ->orWhere('assign_group', 'like', '%'.$div.'%')
                    ->orWhere('wizard_data', 'like', '%'.$div.'%');
            });
        }

        // Add relationship counts for consent module
        if ($module === 'consent') {
            // records_count diisi dari relasi logs() (tabel consent_logs) —
            // sumber data records yang sebenarnya. Relasi records() lama
            // (consent_records) hampir selalu kosong → count 0 di list.
            $query->withCount(['items', 'logs as records_count']);
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

        // RoPA & DPIA: jalur tulisnya kini tinggal di RopaDpiaWriter, supaya
        // penulis non-HTTP (kunci API mitra, impor massal, agen AI) memakai
        // logika yang sama persis — bukan salinan yang cepat atau lambat
        // menyimpang, sebagaimana sudah terjadi pada temuan F-03.
        //
        // Cabang ropa/dpia di dalam blok besar di bawah menjadi tidak terjangkau.
        // Pembersihannya sengaja dipisah ke commit tersendiri, supaya commit ini
        // bisa dibuktikan setara oleh uji karakterisasi yang tidak disentuh.
        if (in_array($module, ['ropa', 'dpia'], true)) {
            $ctx = ModuleWriteContext::fromRequest($request);
            if (empty($ctx->orgId)) {
                return response()->json(['message' => 'Organization ID is required'], 422);
            }

            try {
                $hasil = app(RopaDpiaWriter::class)->create($module, $request->all(), $ctx);

                return response()->json([
                    'message' => 'Created',
                    'data' => $hasil['record'],
                    'auto_dpia_id' => $hasil['auto_dpia_id'],
                    'auto_lia_id' => $hasil['auto_lia_id'],
                    // Khusus DSR; untuk RoPA/DPIA memang selalu 0, sama seperti dulu.
                    'auto_scope_count' => 0,
                ], 201);
            } catch (\Throwable $e) {
                // \Throwable, bukan \Exception: \Error pun jangan sampai membuat
                // Laravel merender pesan mentah ke klien saat APP_DEBUG=true.
                \Log::error('ModuleCrud store error: '.$e->getMessage(), [
                    'module' => $module,
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'message' => 'Gagal menyimpan data. Silakan coba lagi atau hubungi admin bila berlanjut.',
                ], 500);
            }
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

            // Divisi pembuat dikunci ke penugasan — hanya untuk modul yang
            // memang punya kolomnya. RoPA & DPIA tidak sampai ke sini
            // (dikerjakan RopaDpiaWriter); yang tersisa dari daftar itu adalah
            // DSR dan consent.
            if (in_array($module, self::MODUL_BERPENUGASAN, true)) {
                $data = PenugasanDivisi::saatBuat($data, $request->user());
            }

            // Ensure boolean fields are properly cast
            if ($module === 'breach') {
                $data['notification_required'] = filter_var($data['notification_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['is_simulation'] = filter_var($data['is_simulation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['affected_subjects_count'] = (int) ($data['affected_subjects_count'] ?? 0);
                // Multi-RoPA linkage: normalize linked_ropa_ids + sync legacy linked_ropa_id.
                $data = $this->normalizeBreachRopaLinks($data);
                $data = $this->normalizeBreachVendorLinks($data);
            }

            // Auto-generate codes
            switch ($module) {
                // ropa & dpia tidak lagi sampai ke sini — penomorannya dikerjakan
                // RopaDpiaWriter lewat ModuleCodeGenerator.
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
                        // PP 33/2026 Pasal 114(2): batas 3×24 jam dihitung sejak Kegagalan
                        // Pelindungan Data Pribadi DIKETAHUI (detected_at), bukan sejak record
                        // dibuat — insiden yang baru dicatat setelah deteksi tidak boleh dapat
                        // tenggat lebih longgar.
                        $data['notification_deadline'] = $data['notification_deadline']
                            ?? Carbon::parse($data['detected_at'])->addHours(72);
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

            // Create dengan retry pada unique-code collision. Dua create
            // hampir-bersamaan (double submit, atau dari tab/user berbeda)
            // bisa menghitung registration_number/request_id/incident_code/
            // collection_id yang sama dari max+1 → unique violation
            // (SQLSTATE 23000). Regenerate code lalu coba lagi (maks 3x).
            $codeField = match ($module) {
                'ropa', 'dpia' => 'registration_number',
                'dsr' => 'request_id',
                'consent' => 'collection_id',
                'breach' => 'incident_code',
                default => null,
            };
            $codePrefix = match ($module) {
                'ropa' => 'ROPA', 'dpia' => 'DPIA', 'dsr' => 'DSR',
                'consent' => 'CNT', 'breach' => 'BRC', default => null,
            };
            $record = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $record = $model->create($data);
                    break;
                } catch (QueryException $qe) {
                    // 23000 = integrity constraint violation (incl. duplicate entry).
                    $isDup = $qe->getCode() === '23000'
                        || str_contains($qe->getMessage(), 'Duplicate entry')
                        || str_contains($qe->getMessage(), 'UNIQUE constraint');
                    if ($isDup && $codeField && $codePrefix && $attempt < 2) {
                        // Regenerate hanya untuk modul yang pakai legacy prefix-year code.
                        $data[$codeField] = $this->nextCode($codePrefix, $model, $data['org_id']);

                        continue;
                    }
                    throw $qe;
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
                // Breach: any new incident is a critical alert to DPO + admin tenant.
                if ($module === 'breach') {
                    NotificationService::dispatch(
                        kind: 'alert',
                        severity: 'critical',
                        module: 'breach',
                        type: 'breach.created',
                        recipient: 'role:dpo,admin',
                        orgId: $record->org_id,
                        title: "🚨 Insiden baru: {$record->incident_code}",
                        body: ($record->description ?? 'Data breach detected').' — 72 jam untuk notifikasi.',
                        actionUrl: "/breach/{$record->id}",
                        metadata: ['record_id' => $record->id, 'incident_code' => $record->incident_code]
                    );
                }
                // Modul tenant lain: info notification "record baru dibuat" ke
                // DPO + admin tenant supaya mereka aware tanpa harus polling
                // list. Hanya untuk modul yang punya code identitas.
                // RoPA/DPIA tidak lagi di sini — notifikasinya ikut pindah ke
                // RopaDpiaWriter bersama jalur tulisnya.
                $moduleNotifMeta = [
                    'dsr' => ['label' => 'DSR', 'sev' => 'medium'],
                    'consent' => ['label' => 'Consent Point', 'sev' => 'low'],
                ];
                if (isset($moduleNotifMeta[$module])) {
                    $m = $moduleNotifMeta[$module];
                    $code = $record->registration_number ?? $record->request_id ?? $record->collection_id ?? $record->incident_code ?? '';
                    NotificationService::dispatch(
                        kind: 'info',
                        severity: $m['sev'],
                        module: $module,
                        type: "{$module}.created",
                        recipient: 'role:dpo,admin',
                        orgId: $record->org_id,
                        title: "{$m['label']} baru dibuat".($code ? ": {$code}" : ''),
                        body: $record->processing_activity ?? $record->description ?? $record->name ?? $record->requester_name ?? '',
                        actionUrl: "/{$module}/{$record->id}",
                        metadata: ['record_id' => $record->id]
                    );
                }
            } catch (\Throwable $e) {
                // \Throwable (bukan cuma \Exception) supaya \Error dari
                // provider misconfig (mis. credential AI/SMTP/Telegram) tidak
                // bubble ke outer catch dan menggagalkan create record.
                \Log::warning('Notification dispatch failed on create: '.$e->getMessage());
            }

            // DPIA & LIA otomatis kini lahir di RopaDpiaWriter bersama jalur
            // tulis RoPA-nya. Kedua variabel ini tetap ada karena amplop response
            // di bawah dipakai bersama semua modul — untuk dsr/consent/breach
            // nilainya memang selalu null, persis seperti sebelumnya.
            $autoDpiaId = null;
            $autoLiaId = null;

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
                'auto_lia_id' => $autoLiaId,
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
                ->where('origin', '!=', 'built_in')
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

        // RoPA & DPIA: aturan pembaruannya tinggal di RopaDpiaWriter — TERMASUK
        // kedua kunci penyuntingan. Kalau kunci itu ditinggal di sini, jalur
        // tulis lain (kunci API mitra, impor, agen AI) akan melewatinya: mereka
        // bisa menyunting record yang sedang terkunci untuk review, padahal dari
        // aplikasi tidak bisa.
        //
        // Gerbang izin dan penyaringan visibilitas di atas SENGAJA tetap di sini:
        // itu urusan "siapa yang meminta dan boleh melihat apa", bukan aturan
        // modulnya. Cabang ropa/dpia di bawah menjadi tidak terjangkau dan
        // dibersihkan terpisah, bersama cabang store().
        if (in_array($module, ['ropa', 'dpia'], true)) {
            try {
                $hasil = app(RopaDpiaWriter::class)->update(
                    $module,
                    $record,
                    $request->all(),
                    ModuleWriteContext::fromRequest($request),
                );

                return response()->json([
                    'message' => 'Updated',
                    'data' => $hasil['record']->fresh(),
                ]);
            } catch (ModuleWriteRejected $e) {
                return response()->json($e->toResponseBody(), $e->status);
            }
        }

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
                    recipient: 'role:dpo,admin',
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

        // Kunci penyuntingan RoPA/DPIA (assign-group dan konten saat `waiting`)
        // kini ditegakkan RopaDpiaWriter::guardEditLocks, supaya jalur tulis lain
        // tidak bisa melewatinya.

        // Detect wizard_data changes for audit logging
        $oldWizard = $record->wizard_data ?? [];
        $newWizard = $request->input('wizard_data', []);
        $oldStatus = $record->status;
        $oldAssignees = $record->assignees ?? [];
        $payload = $request->all();

        // Divisi asal tidak boleh dilepas — dibaca dari RECORD, bukan dari
        // divisi orang yang sedang mengubah. Sekaligus membuang
        // `origin_division` dari payload, sehingga kuncinya tidak bisa
        // dipindahkan lewat satu baris kiriman.
        if (in_array($module, self::MODUL_BERPENUGASAN, true)) {
            $payload = PenugasanDivisi::saatUbah($payload, $record->origin_division ?? null);
        }

        if ($module === 'breach') {
            $payload = $this->normalizeBreachRopaLinks($payload);
            $payload = $this->normalizeBreachVendorLinks($payload);
        }
        if ($module === 'ropa') {
            // Merge current state so calculator sees the union (wizard_data
            // may come partially).
            $merged = array_merge(
                ['wizard_data' => $record->wizard_data, 'risk_level_locked' => $record->risk_level_locked, 'risk_level' => $record->risk_level],
                $payload
            );
            $payload['risk_level'] = $merged['risk_level'];
            $payload['wizard_data'] = $merged['wizard_data'];
        }
        $record->update($payload);

        // Status-change notification untuk ropa/dpia/dsr ke DPO + admin tenant.
        // (breach sudah ditangani di blok timeline khusus di atas.)
        if (in_array($module, ['ropa', 'dpia', 'dsr'], true)
            && array_key_exists('status', $payload)
            && $payload['status'] !== $oldStatus
        ) {
            try {
                $label = ['ropa' => 'RoPA', 'dpia' => 'DPIA', 'dsr' => 'DSR'][$module];
                $code = $record->registration_number ?? $record->request_id ?? '';
                $newStatus = (string) $payload['status'];
                // approved/rejected = lebih penting → warning; lainnya info.
                $sev = in_array($newStatus, ['approved', 'rejected', 'waiting'], true) ? 'medium' : 'low';
                NotificationService::dispatch(
                    kind: $newStatus === 'rejected' ? 'warning' : 'info',
                    severity: $sev, module: $module, type: "{$module}.status.{$newStatus}",
                    recipient: 'role:dpo,admin', orgId: $record->org_id,
                    title: "{$label} {$code}: status → {$newStatus}",
                    body: ($oldStatus ?? '-')." → {$newStatus}",
                    actionUrl: "/{$module}/{$record->id}",
                    metadata: ['record_id' => $record->id, 'old_status' => $oldStatus, 'new_status' => $newStatus],
                );
            } catch (\Throwable $e) {
                \Log::warning("{$module} status notif failed: ".$e->getMessage());
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

                // Per-record field customization (hide & reorder) — log sebagai
                // entri history yang bersih, bukan diff field numerik.
                if (in_array($sectionKey, ['hidden_fields', 'field_order'], true)) {
                    if (json_encode($oldSection) !== json_encode($sectionData)) {
                        $action = $sectionKey === 'hidden_fields' ? 'fields_hidden_changed' : 'fields_reordered';
                        AuditLog::log($module, $record->id, $action, [
                            $sectionKey => ['old' => $oldSection ?: null, 'new' => $sectionData ?: null],
                        ], $sectionKey);
                    }

                    continue;
                }

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

        // DPIA otomatis saat risiko RoPA naik ke tinggi kini dikerjakan
        // RopaDpiaWriter::update().

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
