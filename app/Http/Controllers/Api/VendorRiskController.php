<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;
use App\Services\AiService;
use App\Services\ApprovalWorkflowDispatcher;
use App\Services\AssessmentAutoTriggerService;
use App\Services\AssessmentTokenService;
use App\Services\DocumentParserService;
use App\Services\FileUploadValidator;
use App\Services\NotificationService;
use App\Services\TenantStorageService;
use App\Services\VendorRiskScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorRiskController extends Controller
{
    /**
     * List all vendors for the organization
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        // Pre-Assessment tabs/badges: filter by PDP scope status. Accept the
        // four state-machine values; ignore anything else.
        $scopeFilter = $request->get('scope');
        $validScopes = [
            Vendor::SCOPE_UNSCREENED, Vendor::SCOPE_IN,
            Vendor::SCOPE_OUT_PENDING, Vendor::SCOPE_OUT,
        ];

        $vendorsQuery = Vendor::where('org_id', $orgId)
            ->when(in_array($scopeFilter, $validScopes, true), fn ($q) => $q->where('pdp_scope_status', $scopeFilter));
        // Division-scoped visibility: non-admin only sees assigned/own-division.
        $this->applyVendorScope($vendorsQuery, $request);

        $vendors = $vendorsQuery
            ->with([
                'assessments' => function ($q) {
                    $q->orderBy('created_at', 'desc')->limit(1);
                },
                'preAssessments' => function ($q) {
                    $q->orderBy('created_at', 'desc')->limit(1);
                },
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($vendor) {
                $pre = $vendor->preAssessments->first();

                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'service' => empty($vendor->services_provided) ? 'Unknown' : implode(', ', $vendor->services_provided),
                    'risk_level' => $vendor->risk_level,
                    'score' => $vendor->risk_score,
                    'dpa_status' => $vendor->dpa_status,
                    'dpa_expires_at' => $vendor->dpa_expires_at,
                    'last_assessed' => $vendor->last_assessed_at ? $vendor->last_assessed_at->format('Y-m-d') : '-',
                    // Pre-Assessment / PDP scope gate — drives FE tabs + badges.
                    'pdp_scope_status' => $vendor->pdp_scope_status,
                    'scope_overridden' => (bool) $vendor->scope_overridden,
                    'pre_assessment' => $pre ? [
                        'id' => $pre->id,
                        'status' => $pre->status,
                        'suggested_scope' => $pre->suggested_scope,
                        'final_scope' => $pre->final_scope,
                    ] : null,
                ];
            });

        return response()->json(['data' => $vendors]);
    }

    private const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    private const DPA_STATUSES = ['none', 'draft', 'signed', 'expired'];

    public function store(Request $request)
    {
        $data = $request->validate($this->writeRules(false));

        $vendor = Vendor::create(array_merge($data, [
            'org_id' => $request->user()->org_id,
        ]));

        // Notify assignees (penanggung jawab) on create — mirror RoPA/DPIA
        // assignee-notify block. Wrapped so notif failures don't fail create.
        try {
            if (! empty($data['assignees']) && is_array($data['assignees'])) {
                foreach ($data['assignees'] as $uid) {
                    NotificationService::dispatch(
                        kind: 'info',
                        severity: 'low',
                        module: 'vendor_risk',
                        type: 'vendor.assigned',
                        recipient: 'user:'.$uid,
                        orgId: $vendor->org_id,
                        title: "Pihak ketiga {$vendor->name} di-assign ke Anda",
                        body: $vendor->description ?? '',
                        actionUrl: "/vendor-risk/{$vendor->id}",
                        metadata: ['record_id' => $vendor->id]
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Vendor assignee notification failed on create: '.$e->getMessage());
        }

        // Sprint X4 — high-risk OR offshore vendor → auto-seed draft TIA.
        // Service handles both criteria + try/catch wrap.
        $autoTiaId = null;
        try {
            $tia = app(AssessmentAutoTriggerService::class)
                ->fromVendor($vendor, $request->user()->id);
            $autoTiaId = $tia?->id;
        } catch (\Throwable $e) {
            Log::warning('Auto-TIA on Vendor store failed (non-fatal): '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Vendor berhasil ditambahkan',
            'data' => $vendor,
            'auto_tia_id' => $autoTiaId,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        // Lean eager-load: hanya kolom yang dibutuhkan UI untuk hindari
        // mengangkat seluruh row vendor_assessments yang punya JSON columns
        // besar (answers, recommendations, score_breakdown) — pernah memicu
        // MySQL sort_buffer overflow di shared hosting.
        $showQuery = Vendor::where('org_id', $request->user()->org_id)
            ->with(['assessments' => function ($q) {
                $q->select([
                    'id', 'vendor_id', 'org_id', 'status',
                    'assessment_token', 'token_expires_at', 'token_consumed_at',
                    'score', 'risk_level', 'submitted_at', 'created_at',
                    'recommendations', 'notes',
                ])->orderBy('created_at', 'desc')->limit(20);
            }]);
        // Division-scoped: a user from another division can't open a vendor by
        // guessing its id — applyVendorScope narrows the query so findOrFail
        // returns 404 (consistent with RoPA single-record gating).
        $this->applyVendorScope($showQuery, $request);
        $vendor = $showQuery->findOrFail($id);

        $data = $vendor->toArray();
        $data['active_assessment_token'] = null;
        $data['active_token_expires_at'] = null;

        // Sertakan token publik aktif (kalau ada) di response root supaya
        // frontend bisa langsung tampilkan tautan share tanpa generate ulang.
        // Pilih assessment terbaru yang token belum expired dan belum di-consume.
        // Wrap try/catch supaya kalau data assessment ter-malformed (mis. carbon
        // cast gagal), endpoint show TIDAK 500 — basic vendor data tetap balik.
        // Pilih assessment dengan token yang masih bisa dipakai untuk SHARE
        // (belum expired, belum consumed). Kalau tidak ada, fall-through ke
        // assessment terbaru yang punya token APAPUN (consumed/expired) untuk
        // ditampilkan READ-ONLY supaya tenant tetap bisa lihat URL hasil.
        try {
            $assessments = $vendor->assessments
                ->filter(fn ($a) => ! empty($a->assessment_token))
                ->sortByDesc('created_at');

            $shareable = $assessments->first(function ($a) {
                $expiresAt = $a->token_expires_at;
                $notExpired = empty($expiresAt) || (is_object($expiresAt) && method_exists($expiresAt, 'isFuture') && $expiresAt->isFuture());
                $notConsumed = empty($a->token_consumed_at);

                return $notExpired && $notConsumed;
            });

            $latestAny = $assessments->first();

            $token = $shareable?->assessment_token ?? $latestAny?->assessment_token;
            $expires = $shareable?->token_expires_at ?? $latestAny?->token_expires_at;
            $consumed = $shareable ? false : ! empty($latestAny?->token_consumed_at);

            if ($token) {
                $data['active_assessment_token'] = $token;
                $data['active_token_expires_at'] = $expires;
                // Hint untuk frontend bedakan mode: shareable (kirim ke vendor)
                // vs read-only (vendor sudah submit, hanya untuk preview hasil).
                $data['active_token_consumed'] = $consumed;
            }
        } catch (\Throwable $e) {
            \Log::warning('vendor.show active_assessment computation failed: '.$e->getMessage());
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate($this->writeRules(true));

        // Assign-lock: RoPA blocks assign changes unless status in_progress/draft.
        // Vendors have no equivalent assign-lock status lifecycle (their state
        // machine is pdp_scope_status / assessment workflow, not an editable
        // draft→waiting gate on the vendor row itself), so assign changes are
        // always allowed here — intentionally no lock.
        $oldAssignees = is_array($vendor->assignees) ? $vendor->assignees : [];

        $vendor->update($data);

        // Notify newly-added assignees (array_diff vs old) — mirror RoPA update.
        try {
            if ($request->has('assignees')) {
                $newAssignees = $request->input('assignees', []);
                if (is_array($newAssignees)) {
                    $added = array_values(array_diff($newAssignees, $oldAssignees));
                    foreach ($added as $uid) {
                        NotificationService::dispatch(
                            kind: 'info',
                            severity: 'low',
                            module: 'vendor_risk',
                            type: 'vendor.assigned',
                            recipient: 'user:'.$uid,
                            orgId: $vendor->org_id,
                            title: "Pihak ketiga {$vendor->name} di-assign ke Anda",
                            body: $vendor->description ?? '',
                            actionUrl: "/vendor-risk/{$vendor->id}",
                            metadata: ['record_id' => $vendor->id]
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Vendor assignee notification failed on update: '.$e->getMessage());
        }

        return response()->json(['message' => 'Vendor berhasil diupdate', 'data' => $vendor->fresh()]);
    }

    /**
     * GET /api/vendor-risk/{id}/assessment-history
     *
     * Phase 4 — list semua VendorAssessment row untuk vendor ini (history).
     * Filter org_id. Plus return reminder flag kalau last_approved > X months
     * (X dari system_settings 'tprm_full_assessment_frequency_months', default 12).
     */
    public function assessmentHistory(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($id);

        $assessments = VendorAssessment::query()
            ->where('vendor_id', $vendor->id)
            ->where('org_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'status', 'score', 'risk_level', 'category',
                'questionnaire_version', 'library_id',
                'source', 'submitted_at', 'created_at',
                'reviewer_id', 'reviewer_actioned_at',
                'approver_id', 'approver_actioned_at',
                'rejection_reason',
            ]);

        // Reminder logic: cek kapan assessment approved terakhir kali
        $lastApproved = $assessments
            ->where('status', VendorAssessment::STATUS_APPROVED)
            ->sortByDesc('approver_actioned_at')
            ->first();

        $frequencyMonths = (int) config('vendor_screening.full_assessment_frequency_months', 12);
        // Fallback hardcoded 12 kalau config tidak ada
        if ($frequencyMonths <= 0) {
            $frequencyMonths = 12;
        }

        $needReassessment = false;
        $monthsSinceLastApproval = null;
        if ($lastApproved && $lastApproved->approver_actioned_at) {
            $monthsSince = now()->diffInMonths($lastApproved->approver_actioned_at);
            $monthsSinceLastApproval = $monthsSince;
            $needReassessment = $monthsSince >= $frequencyMonths;
        } elseif ($assessments->count() === 0) {
            $needReassessment = true;
        }

        return response()->json([
            'data' => [
                'history' => $assessments,
                'reminder' => [
                    'frequency_months' => $frequencyMonths,
                    'months_since_last_approval' => $monthsSinceLastApproval,
                    'need_reassessment' => $needReassessment,
                    'last_approved_at' => $lastApproved?->approver_actioned_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Validation rules shared by store + update.
     * Critical: risk_level + risk_score validated against enum/range — sebelumnya
     * frontend bisa kirim risk_level apa saja dan backend menerima begitu saja.
     */
    private function writeRules(bool $forUpdate): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';
        $opt = $forUpdate ? 'sometimes|nullable' : 'nullable';

        return [
            'name' => "{$req}|string|max:255",
            'description' => "{$opt}|string|max:2000",
            'website' => "{$opt}|url|max:500",
            'privacy_policy_url' => "{$opt}|url|max:500",
            'country' => "{$opt}|string|max:100",
            'services_provided' => "{$opt}|array",
            'services_provided.*' => 'string|max:200',
            'data_shared' => "{$opt}|array",
            'data_shared.*' => 'string|max:200',
            'risk_level' => "{$opt}|in:".implode(',', self::RISK_LEVELS),
            'risk_score' => "{$opt}|integer|min:0|max:100",
            'dpa_status' => "{$opt}|in:".implode(',', self::DPA_STATUSES),
            'dpa_signed_at' => "{$opt}|date",
            'dpa_expires_at' => "{$opt}|date|after_or_equal:dpa_signed_at",
            'last_assessed_at' => "{$opt}|date",
            'contact_name' => "{$opt}|string|max:200",
            'contact_email' => "{$opt}|email|max:200",
            // Phase 2 — TPRM category
            'category' => "{$opt}|in:".implode(',', VendorQuestionnaire::ALL_CATEGORIES),
            // Assignment + division-scoped visibility (mirrors RoPA).
            'assign_group' => "{$opt}|string|max:255",
            'assignees' => "{$opt}|array",
            'assignees.*' => 'uuid',
        ];
    }

    /**
     * Per-user/divisi access scope for vendors (pihak ketiga) — mirrors
     * ModuleCrudController::applyRopaUserScope. Non-admin/non-DPO hanya lihat
     * vendor:
     *   (a) assign_group NULL atau '(All Group)' — terbuka untuk semua user
     *       di tenant yang sama (tenant boundary tetap via org_id),
     *   (b) user.id ada di kolom JSON assignees,
     *   (c) user.department.name === vendor.assign_group (saat Per Divisi,
     *       assign_group menyimpan nama divisi seperti RoPA),
     * Vendor TIDAK punya kolom created_by sehingga klausa creator RoPA
     * dihilangkan (di-skip secara sengaja).
     *
     * Role exemption disamakan persis dengan RoPA: role kolom
     * superadmin/admin/dpo ATAU tenantRole.name (case-insensitive) admin/dpo
     * bypass. Tenant boundary tetap dijaga oleh where('org_id', ...) pemanggil.
     */
    private function applyVendorScope($query, Request $request): void
    {
        $query->visibleTo($request->user());
    }

    // =========================================================
    //  Phase 2 — Deterministic questionnaire (no AI required)
    // =========================================================

    /**
     * Get the questionnaire for a category — used by the FE wizard
     * Step 2 to render the form. Platform-level data so org_id is irrelevant.
     */
    public function getQuestionnaire(Request $request, string $category)
    {
        if (! in_array($category, VendorQuestionnaire::ALL_CATEGORIES, true)) {
            return response()->json(['message' => 'Unknown vendor category'], 422);
        }
        // Default version: v2_2026 (56 pertanyaan PDP) untuk pdp_compliance,
        // v1 untuk kategori lama (cloud/saas/data_processor).
        $defaultVersion = $category === VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE
            ? 'v2_2026'
            : 'v1';
        $version = $request->get('version', $defaultVersion);

        $questions = VendorQuestionnaire::query()
            ->where('category', $category)
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'category' => $category,
            'category_label' => VendorQuestionnaire::CATEGORY_LABELS[$category] ?? $category,
            'category_description' => VendorQuestionnaire::CATEGORY_DESCRIPTIONS[$category] ?? null,
            'version' => $version,
            'sections' => VendorQuestionnaire::SECTION_LABELS,
            'data' => $questions,
        ]);
    }

    /**
     * Available vendor categories — for the wizard Step 1 picker.
     */
    public function listCategories()
    {
        // Hide kategori legacy (cloud_infrastructure / saas / data_processor)
        // dari picker UI. Hanya pdp_compliance (56 pertanyaan komprehensif
        // lintas bidang) yang dipakai sebagai default. Kategori legacy tetap
        // ada di model untuk backward-compat data lama, tapi tidak di-expose
        // ke wizard baru.
        $visible = [VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE];

        $list = collect($visible)
            ->map(function ($c) {
                // pdp_compliance dipakai v2_2026 (56 PDP), kategori lama v1.
                $version = $c === VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE
                    ? 'v2_2026'
                    : 'v1';

                return [
                    'value' => $c,
                    'label' => VendorQuestionnaire::CATEGORY_LABELS[$c] ?? $c,
                    'description' => VendorQuestionnaire::CATEGORY_DESCRIPTIONS[$c] ?? null,
                    'version' => $version,
                    'question_count' => VendorQuestionnaire::query()
                        ->where('category', $c)
                        ->where('version', $version)
                        ->where('is_active', true)
                        ->count(),
                ];
            })->values();

        return response()->json(['data' => $list]);
    }

    /**
     * Run the deterministic scoring formula against a set of answers
     * and persist the result as a VendorAssessment. Updates vendor's
     * cached risk_score/risk_level/last_assessed_at + next_assessment_due_at.
     *
     * Mode 'create_vendor' — creates a brand-new vendor with the answers
     * (Step 1 of wizard captures vendor metadata, Step 2-3 questionnaire).
     * Mode 'reassess'      — reassesses an existing vendor.
     */
    public function assessDeterministic(
        Request $request,
        VendorRiskScoreService $scorer,
        ?string $id = null,
    ) {
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', VendorQuestionnaire::ALL_CATEGORIES),
            'answers' => 'required|array',
            'version' => 'nullable|string|max:16',
            'notes' => 'nullable|string|max:2000',
            // For create_vendor mode — vendor metadata
            'vendor_name' => 'sometimes|required_without:id|string|max:255',
            'vendor_country' => 'nullable|string|max:100',
            'vendor_website' => 'nullable|url|max:500',
            'vendor_services' => 'nullable|array',
            'vendor_services.*' => 'string|max:200',
        ]);

        $orgId = $request->user()->org_id;
        $version = $data['version'] ?? 'v1';

        // Resolve or create the vendor
        if ($id) {
            $vendor = Vendor::where('org_id', $orgId)->findOrFail($id);
            // SCOPE GATE: out-of-scope third parties don't need a full assessment.
            if ($vendor->pdp_scope_status === Vendor::SCOPE_OUT) {
                return response()->json([
                    'message' => 'Pihak ketiga ini Di Luar Lingkup PDP — tidak memerlukan assessment.',
                ], 422);
            }
            $vendor->category = $data['category'];
        } else {
            $vendor = Vendor::create([
                'org_id' => $orgId,
                'name' => $data['vendor_name'],
                'category' => $data['category'],
                'country' => $data['vendor_country'] ?? null,
                'website' => $data['vendor_website'] ?? null,
                'services_provided' => $data['vendor_services'] ?? [],
            ]);
        }

        // Compute score
        $result = $scorer->compute($data['category'], $data['answers'], $version);

        // Persist assessment
        $assessment = VendorAssessment::create([
            'vendor_id' => $vendor->id,
            'org_id' => $orgId,
            'assessed_by' => $request->user()->id,
            'answers' => $data['answers'],
            'score' => $result['score'],
            'risk_level' => $result['risk_level'],
            'recommendations' => $result['recommendations'],
            'notes' => $data['notes'] ?? null,
            'source' => VendorAssessment::SOURCE_DETERMINISTIC,
            'category' => $data['category'],
            'score_breakdown' => $result['breakdown'],
            'questionnaire_version' => $version,
        ]);

        // Update vendor cached fields
        $vendor->risk_score = $result['score'];
        $vendor->risk_level = $result['risk_level'];
        $vendor->last_assessed_at = now();
        $vendor->next_assessment_due_at = $scorer->nextDueDate($result['risk_level']);
        $vendor->save();

        // Notif ke DPO + admin tenant — severity ikut tingkat risiko hasil asesmen.
        try {
            $sev = in_array($result['risk_level'], ['high', 'critical'], true) ? 'high' : 'low';
            NotificationService::dispatch(
                kind: in_array($result['risk_level'], ['high', 'critical'], true) ? 'warning' : 'info',
                severity: $sev, module: 'vendor-risk', type: 'vendor.assessed',
                recipient: 'role:dpo,admin', orgId: $vendor->org_id,
                title: "Asesmen pihak ketiga selesai: {$vendor->vendor_name}",
                body: 'Skor '.$result['score'].'/100 — risiko '.strtoupper($result['risk_level']).'.',
                actionUrl: '/vendor-risk', metadata: ['record_id' => $vendor->id],
            );
        } catch (\Throwable $e) {
            \Log::warning('vendor.assessed notif failed: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Assessment selesai. Skor: '.$result['score'].'/100 ('.strtoupper($result['risk_level']).').',
            'data' => [
                'vendor' => $vendor->fresh(),
                'assessment' => $assessment,
                'questions_total' => $result['questions_total'],
                'questions_answered' => $result['questions_answered'],
                'unanswered' => $result['unanswered'] ?? [],
                'breakdown' => $result['breakdown'],
                'recommendations' => $result['recommendations'],
            ],
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->delete();

        return response()->json(['message' => 'Vendor dipindahkan ke tempat sampah']);
    }

    /**
     * Submit assessment vendor untuk approval. Trigger Approval Workflow
     * berdasarkan config /settings → Approval Workflow (module=vendor_risk).
     * Dipakai setelah assessment selesai dan butuh sign-off compliance.
     */
    public function submitForApproval(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);

        $wf = ApprovalWorkflowDispatcher::dispatch(
            $vendor->org_id, 'vendor_risk', $vendor->id
        );

        return response()->json([
            'message' => 'Submitted for approval',
            'workflow' => $wf,
        ]);
    }

    public function trashed(Request $request)
    {
        $query = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id);
        $this->applyVendorScope($query, $request);
        $vendors = $query->orderBy('deleted_at', 'desc')->get();

        return response()->json(['data' => $vendors]);
    }

    public function restore(Request $request, $id)
    {
        $vendor = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->restore();

        return response()->json(['message' => 'Vendor berhasil dipulihkan']);
    }

    public function forceDelete(Request $request, $id)
    {
        $vendor = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->forceDelete();

        return response()->json(['message' => 'Vendor dihapus permanen']);
    }

    // =========================================================
    //  Sprint D3: TPRM document management
    // =========================================================

    public function uploadDocument(Request $request, string $id, TenantStorageService $storage)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,docx,doc,xlsx|max:15360', // 15MB
        ]);

        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);

        $file = $request->file('file');
        $stored = $storage->storeTenantPrivateFile($org, $file, "vendors/{$vendor->id}");
        $path = $stored['path'];

        $documents = $vendor->documents ?? [];
        $documents[] = [
            'id' => (string) Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'type' => strtolower($file->getClientOriginalExtension()),
            'size' => $file->getSize(),
            'driver' => $stored['driver'],
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_by' => $request->user()->id,
        ];
        $vendor->update(['documents' => $documents]);

        return response()->json(['message' => 'Dokumen terupload', 'data' => $documents]);
    }

    public function deleteDocument(Request $request, string $id, string $docId, TenantStorageService $storage)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);
        $docs = $vendor->documents ?? [];
        $doc = collect($docs)->firstWhere('id', $docId);
        if ($doc && ! empty($doc['path'])) {
            try {
                $storage->getDisk($org)->delete($doc['path']);
            } catch (\Throwable $e) { /* best-effort */
            }
        }
        $vendor->update(['documents' => array_values(array_filter($docs, fn ($d) => ($d['id'] ?? null) !== $docId))]);

        return response()->json(['message' => 'Dokumen dihapus', 'data' => $vendor->documents]);
    }

    /**
     * Screen vendor documents — dua mode:
     *  - mode=manual (default kalau AI gak available / user pilih): parse semua
     *    dokumen, return raw text supaya user bisa baca manual & kasih skor
     *    sendiri lewat update endpoint. TIDAK ada AI dependency.
     *  - mode=ai: parse + kirim ke AI vendorRiskAssessor untuk auto-score.
     *
     * Per-doc parse errors di-surface (bukan di-swallow) — sebelumnya user lihat
     * "Tidak ada dokumen yang bisa di-parse" walau ada 10 dokumen yang gagal.
     */
    public function screenDocuments(Request $request, string $id, TenantStorageService $storage)
    {
        $request->validate(['mode' => 'nullable|in:manual,ai,auto']);
        $mode = $request->input('mode', 'auto');

        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);
        $docs = $vendor->documents ?? [];
        if (count($docs) === 0) {
            return response()->json(['message' => 'Vendor tidak punya dokumen untuk di-screen'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        $aiAvailable = $ai->isAvailable() && ($org->ai_credits_remaining ?? 0) > 0;
        $useAi = $mode === 'ai' || ($mode === 'auto' && $aiAvailable);

        if ($mode === 'ai' && ! $aiAvailable) {
            return response()->json([
                'message' => 'AI tidak tersedia (API key kosong atau credits habis). Gunakan mode=manual untuk parse-only.',
            ], 503);
        }

        $parser = new DocumentParserService;
        $summaries = [];
        $parseErrors = [];
        foreach ($docs as $d) {
            if (empty($d['path'])) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => 'path kosong'];

                continue;
            }
            try {
                [$fullPath, $cleanup] = $storage->getLocalPathForProcessing($org, $d['path']);
            } catch (\Throwable $e) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => 'storage tidak terjangkau: '.$e->getMessage()];
                Log::warning("screenDocuments storage resolve failed for {$d['name']}: ".$e->getMessage());

                continue;
            }
            try {
                $parsed = $parser->parse($fullPath, $d['type'] ?? 'pdf');
                $summaries[] = [
                    'doc' => $d['name'] ?? '',
                    'text' => mb_substr($parsed['raw_text'] ?? '', 0, 5000),
                ];
            } catch (\Throwable $e) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => $e->getMessage()];
                Log::warning("screenDocuments parse failed for {$d['name']}: ".$e->getMessage());
            } finally {
                $cleanup();
            }
        }

        if (count($summaries) === 0) {
            return response()->json([
                'message' => 'Tidak ada dokumen yang berhasil di-parse',
                'parse_errors' => $parseErrors,
            ], 422);
        }

        // Manual mode: return parsed text, user kasih skor sendiri via update endpoint
        if (! $useAi) {
            return response()->json([
                'message' => 'Parse selesai (manual mode) — review teks lalu update risk_score/level secara manual.',
                'mode' => 'manual',
                'documents' => $summaries,
                'parse_errors' => $parseErrors,
                'vendor' => $vendor,
            ]);
        }

        // AI mode
        $combinedText = '';
        foreach ($summaries as $s) {
            $combinedText .= "=== {$s['doc']} ===\n{$s['text']}\n\n";
        }

        try {
            $response = $ai->vendorRiskAssessor([
                'vendor' => ['name' => $vendor->name, 'services' => $vendor->services_provided ?? []],
                'documents_text' => mb_substr($combinedText, 0, 12000),
            ]);
        } catch (\Throwable $e) {
            Log::error('screenDocuments AI call failed: '.$e->getMessage());

            return response()->json([
                'message' => 'AI gagal — gunakan mode=manual untuk lihat hasil parse.',
                'mode' => 'ai',
                'ai_error' => $e->getMessage(),
                'documents' => $summaries,
                'parse_errors' => $parseErrors,
            ], 502);
        }

        if ($response && isset($response['score'])) {
            $vendor->update([
                'risk_score' => (int) $response['score'],
                'risk_level' => $response['risk_level'] ?? $vendor->risk_level,
                'last_assessed_at' => now(),
            ]);
            $org->decrement('ai_credits_remaining', 1);
        }

        return response()->json([
            'message' => 'Screening selesai (AI-assisted)',
            'mode' => 'ai',
            'data' => $response,
            'documents' => $summaries,
            'parse_errors' => $parseErrors,
            'vendor' => $vendor->fresh(),
        ]);
    }

    /**
     * Re-assess existing vendor — entry point untuk "Jalankan Ulang Audit"
     * di detail modal. Manual mode: caller kirim score+level langsung.
     * AI mode: kirim updated answers untuk re-scoring.
     *
     * Ini menggantikan placeholder "belum diimplementasi" toast di frontend.
     */
    public function reassess(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);

        // SCOPE GATE: out-of-scope third parties don't need a full assessment.
        if ($vendor->pdp_scope_status === Vendor::SCOPE_OUT) {
            return response()->json([
                'message' => 'Pihak ketiga ini Di Luar Lingkup PDP — tidak memerlukan assessment.',
            ], 422);
        }

        $validated = $request->validate([
            'mode' => 'required|in:manual,ai',
            'answers' => 'nullable|array',
            'manual_score' => 'nullable|integer|min:0|max:100',
            'manual_risk_level' => 'nullable|in:'.implode(',', self::RISK_LEVELS),
            'notes' => 'nullable|string|max:5000',
            'recommendations' => 'nullable|array',
            'recommendations.*' => 'string|max:500',
        ]);

        if ($validated['mode'] === 'manual') {
            if (! isset($validated['manual_score'], $validated['manual_risk_level'])) {
                return response()->json(['message' => 'manual_score + manual_risk_level wajib untuk mode manual'], 422);
            }

            $assessment = VendorAssessment::create([
                'vendor_id' => $vendor->id,
                'org_id' => $vendor->org_id,
                'assessed_by' => $request->user()->id,
                'answers' => $validated['answers'] ?? [],
                'score' => $validated['manual_score'],
                'risk_level' => $validated['manual_risk_level'],
                'recommendations' => $validated['recommendations'] ?? [],
                'notes' => $validated['notes'] ?? null,
            ]);
            $vendor->update([
                'risk_score' => $validated['manual_score'],
                'risk_level' => $validated['manual_risk_level'],
                'last_assessed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Re-assessment manual tersimpan.',
                'mode' => 'manual',
                'assessment' => $assessment,
                'vendor' => $vendor->fresh(),
            ]);
        }

        // AI mode
        $org = $request->user()->organization;
        if (($org->ai_credits_remaining ?? 0) < 1) {
            return response()->json(['message' => 'AI credits habis. Gunakan mode=manual.'], 402);
        }
        if (empty($validated['answers'])) {
            return response()->json(['message' => 'answers wajib untuk mode AI'], 422);
        }

        try {
            $aiService = (new AiService($vendor->org_id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorRiskAssessor($validated['answers']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI gagal: '.$e->getMessage(), 'fallback' => 'gunakan mode=manual'], 502);
        }

        if (! $response || ! isset($response['score'])) {
            return response()->json(['message' => 'AI tidak return skor — gunakan mode=manual'], 502);
        }

        $assessment = VendorAssessment::create([
            'vendor_id' => $vendor->id,
            'org_id' => $vendor->org_id,
            'assessed_by' => $request->user()->id,
            'answers' => $validated['answers'],
            'score' => (int) $response['score'],
            'risk_level' => $response['risk_level'] ?? 'medium',
            'recommendations' => $response['recommendations'] ?? [],
            'notes' => json_encode($response['red_flags'] ?? []),
        ]);
        $vendor->update([
            'risk_score' => (int) $response['score'],
            'risk_level' => $response['risk_level'] ?? $vendor->risk_level,
            'last_assessed_at' => now(),
        ]);
        $org->decrement('ai_credits_remaining', 1);

        return response()->json([
            'message' => 'Re-assessment AI selesai.',
            'mode' => 'ai',
            'assessment' => $assessment,
            'vendor' => $vendor->fresh(),
            'data' => $response,
        ]);
    }

    /**
     * 1. AI Auto-Form (Extractor)
     */
    public function extract(Request $request)
    {
        $request->validate(['url' => 'required|string']);

        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            $aiService = (new AiService($org->id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorExtractor($request->url);

            if (! $response) {
                throw new \Exception('AI gagal merespons dengan JSON valid');
            }

            // Deduct exactly after success
            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            Log::error('VendorRisk Extract Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. AI Questionnaire Generator
     */
    public function generateQuestions(Request $request)
    {
        $request->validate(['extracted_data' => 'required|array']);

        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            $aiService = (new AiService($org->id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorQuestionnaire($request->extracted_data);

            if (! $response || ! isset($response['questions'])) {
                throw new \Exception('AI gagal generate pertanyaan');
            }

            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response['questions'],
            ]);

        } catch (\Exception $e) {
            Log::error('VendorRisk QGen Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. AI Vendor Risk Assessor
     */
    public function assess(Request $request)
    {
        $request->validate([
            'extracted_data' => 'required|array',
            'answers' => 'required|array',
        ]);

        $orgId = $request->user()->org_id;
        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            DB::beginTransaction();

            $aiService = (new AiService($orgId))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorRiskAssessor($request->answers);

            if (! $response || ! isset($response['score'])) {
                throw new \Exception('AI gagal men-scoring risiko');
            }

            $extracted = $request->extracted_data;

            // Save to DB
            $vendor = Vendor::create([
                'org_id' => $orgId,
                'name' => $extracted['name'] ?? 'Unknown Vendor',
                'services_provided' => $extracted['services_provided'] ?? [],
                'data_shared' => $extracted['data_shared'] ?? [],
                'description' => $extracted['summary'] ?? null,
                'risk_score' => $response['score'],
                'risk_level' => $response['risk_level'],
                'last_assessed_at' => now(),
            ]);

            $assessment = VendorAssessment::create([
                'vendor_id' => $vendor->id,
                'org_id' => $orgId,
                'assessed_by' => $request->user()->id,
                'answers' => $request->answers,
                'score' => $response['score'],
                'risk_level' => $response['risk_level'],
                'recommendations' => $response['recommendations'] ?? [],
                'notes' => json_encode($response['red_flags'] ?? []),
            ]);

            $org->decrement('ai_credits_remaining', 1);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('VendorRisk Assess Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    //  Sprint G — Public assessment link + typed intake documents
    // =========================================================

    /**
     * Sprint G.6 — Generate public assessment link untuk dikirim ke pihak ketiga.
     *
     * Reuse VendorAssessment draft kalau ada (status='draft'), kalau tidak bikin
     * baru. AssessmentTokenService yang men-set token + token_expires_at + status.
     * Setelah generate, status di-flip ke 'sent' (idempotent kalau dipanggil
     * ulang — service akan re-generate token baru dengan expiry baru).
     *
     * Audit log: module=tprm.send_assessment, action=generate_token, supaya
     * tim compliance bisa trace siapa kirim ke vendor mana dan kapan.
     */
    public function generatePublicLink(Request $request, string $vendorId, AssessmentTokenService $tokenSvc)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($vendorId);

        // SCOPE GATE (Pre-Assessment): pihak ketiga yang sudah ditetapkan Di
        // Luar Lingkup PDP tidak memerlukan asesmen penuh. unscreened/in_scope
        // tetap diizinkan (FE hanya mendorong screening dulu, tidak hard-block).
        if ($vendor->pdp_scope_status === Vendor::SCOPE_OUT) {
            return response()->json([
                'message' => 'Pihak ketiga ini Di Luar Lingkup PDP — tidak memerlukan assessment.',
            ], 422);
        }

        // GUARD: tolak generate tautan baru bila ada assessment yang sudah
        // ter-submit. Skor sudah final — re-share tautan justru membingungkan
        // vendor (kuesioner lock). Frontend juga punya guard sama, tapi
        // backend tetap defensif terhadap bypass / direct API call.
        $hasSubmitted = $vendor->assessments()
            ->where('status', 'submitted')
            ->exists();
        if ($hasSubmitted) {
            return response()->json([
                'message' => 'Asesmen sudah selesai diisi oleh pihak ketiga. Tautan baru tidak dapat dibuat. Untuk mengulang penilaian, hubungi superadmin.',
            ], 422);
        }

        // TPRM Phase 1: terima library_id opsional dari client supaya
        // pertanyaan yang dirender di public page mengikuti library yang
        // dipilih. Validasi library visible untuk org ini.
        $libraryId = $request->input('library_id');
        if ($libraryId) {
            $lib = QuestionLibrary::query()
                ->withoutGlobalScope('org')
                ->visibleTo($vendor->org_id)
                ->where('id', $libraryId)
                ->where('is_active', true)
                ->first();
            if (! $lib) {
                return response()->json([
                    'message' => 'Library yang dipilih tidak valid atau tidak dapat diakses.',
                ], 422);
            }
        }

        // firstOrCreate dengan attributes search-only + values default supaya
        // record baru di-stamp dengan questionnaire_version + answers kosong.
        $assessment = VendorAssessment::firstOrCreate(
            [
                'vendor_id' => $vendor->id,
                'org_id' => $vendor->org_id,
                'status' => 'draft',
            ],
            [
                'questionnaire_version' => 'v2_2026',
                'answers' => [],
                'library_id' => $libraryId,
            ]
        );

        // Kalau row existing (firstOrCreate hit lama) dan client kirim pilihan
        // library berbeda, update supaya pertanyaan yang dirender konsisten.
        // `exists('library_id')` membedakan "key dikirim bernilai null" (=
        // pilih Default / pertanyaan org saat ini → CLEAR library_id, jalur
        // effectiveForOrg) dari "key tidak dikirim" (legacy client → no-op).
        if ($request->exists('library_id') && $assessment->library_id !== ($libraryId ?: null)) {
            $assessment->update(['library_id' => $libraryId ?: null]);
        }

        // Generate token (default expiry 30 hari, configurable via
        // system_settings tprm_public_link_expiry_days). Service set status='sent'.
        $token = $tokenSvc->generate($assessment);
        $assessment->refresh();

        // Build public URL — frontend_url fallback ke app.url biar konsisten
        // dengan controller lain (Auth/Notification/Dsr broadcaster).
        $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:3000'));
        $publicUrl = rtrim((string) $baseUrl, '/').'/asesmen-pihak-ketiga/'.$token;

        // Audit log — token disimpan prefix-only supaya kalau leak tidak
        // langsung pakai. Investigator bisa rekonstruksi full token dari DB.
        AuditLog::create([
            'org_id' => $vendor->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'tprm.send_assessment',
            'action' => 'generate_token',
            'record_id' => $assessment->id,
            'section' => 'vendor_assessment',
            'changes' => [
                'vendor' => $vendor->name,
                'token_prefix' => substr($token, 0, 8),
                'expires_at' => optional($assessment->token_expires_at)->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tautan asesmen berhasil dibuat. Bagikan URL berikut kepada pihak ketiga.',
            'assessment_id' => $assessment->id,
            'token' => $token,
            'public_url' => $publicUrl,
            'expires_at' => $assessment->token_expires_at,
        ]);
    }

    /**
     * Sprint G.2 — Upload dokumen vendor typed (intake form).
     *
     * Berbeda dengan uploadDocument() yang menerima dokumen bebas dan menyimpan
     * sebagai array list, endpoint ini meng-keyed berdasarkan jenis dokumen
     * (akta_notaris/ktp/kontrak_kerjasama/company_profile) sehingga upload
     * berikutnya untuk kind yang sama akan menimpa entri sebelumnya — sesuai
     * UX intake form di frontend.
     *
     * File divalidasi via FileUploadValidator (magic-byte check, not just MIME)
     * supaya tidak bisa upload file berbahaya yang diberi ekstensi .pdf.
     */
    public function uploadIntakeDocument(Request $request, string $vendorId, TenantStorageService $storage, FileUploadValidator $validator)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($vendorId);

        $request->validate([
            'kind' => 'required|in:akta_notaris,ktp,kontrak_kerjasama,company_profile',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        try {
            $validator->validate($request->file('file'), FileUploadValidator::PRESET_DOCUMENT);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = $request->user()->organization;
        $result = $storage->storeTenantPrivateFile(
            $org,
            $request->file('file'),
            "vendors/{$vendor->id}/documents"
        );

        // Keyed-by-kind: timpa entri sebelumnya untuk kind yang sama supaya
        // user bisa re-upload dokumen yang salah tanpa duplikasi. Existing
        // free-form documents (array list dari uploadDocument lama) tetap
        // dipertahankan dengan key numerik — kita hanya set key string.
        $documents = $vendor->documents ?? [];
        $documents[$request->kind] = [
            'path' => $result['path'],
            'driver' => $result['driver'],
            'filename' => $request->file('file')->getClientOriginalName(),
            'size' => $request->file('file')->getSize(),
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_by' => $request->user()->id,
        ];
        $vendor->update(['documents' => $documents]);

        AuditLog::create([
            'org_id' => $vendor->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'tprm.intake_document',
            'action' => 'upload',
            'record_id' => $vendor->id,
            'section' => 'vendor_documents',
            'field' => $request->kind,
            'changes' => [
                'kind' => $request->kind,
                'filename' => $request->file('file')->getClientOriginalName(),
                'size' => $request->file('file')->getSize(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Dokumen berhasil diunggah.',
            'kind' => $request->kind,
            'document' => $documents[$request->kind],
        ]);
    }
}
