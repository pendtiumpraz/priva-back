<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HoldingAssessmentInstance;
use App\Models\HoldingAssessmentQuestion;
use App\Models\HoldingAssessmentTemplate;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Holding Compliance Assessment — holding-side management.
 *
 * Org HOLDING meng-author template (pertanyaan custom per kategori, per regulasi),
 * lalu men-dispatch ke anak perusahaan / sub-holding sebagai instance yang diisi
 * via public link (pola TPRM). Reviewer holding menilai di dashboard (lihat
 * HoldingAssessmentReviewController — Fase 4).
 *
 * Akses: root / superadmin, atau admin dari org ber-level holding/sub_holding.
 * Mengikuti pola self-check HoldingDashboardController (bukan permission slug).
 */
class HoldingAssessmentController extends Controller
{
    /**
     * Pastikan caller adalah holding admin (atau platform root/superadmin).
     * Return [holdingOrg|null, descendantOrgIds[]]. abort(403) kalau bukan.
     */
    private function resolveHolding(Request $request): array
    {
        $user = $request->user();
        $role = $user->role ?? null;

        if (in_array($role, ['root', 'superadmin'], true)) {
            // Platform admin: konteks holding = org user (kalau ada) atau null.
            $org = $user->org_id ? Organization::find($user->org_id) : null;
            $ids = $org ? array_merge($org->getDescendantIds(), [$org->id]) : [];

            return [$org, $ids];
        }

        $org = $user->org_id ? Organization::find($user->org_id) : null;
        if (! $org || ! $org->isHolding()) {
            abort(403, 'Hanya holding / sub-holding admin yang dapat mengakses Holding Assessment.');
        }
        $ids = array_merge($org->getDescendantIds(), [$org->id]);

        return [$org, $ids];
    }

    // ===================================================================
    // TEMPLATES
    // ===================================================================

    public function indexTemplates(Request $request)
    {
        $this->resolveHolding($request);

        $templates = HoldingAssessmentTemplate::query()
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->withCount(['questions', 'instances'])
            ->orderByDesc($request->boolean('trashed') ? 'deleted_at' : 'created_at')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function showTemplate(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::with(['questions'])->findOrFail($id);

        return response()->json(['data' => $template]);
    }

    public function storeTemplate(Request $request)
    {
        $this->resolveHolding($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'regulation_code' => ['nullable', 'string', 'max:40'],
            'regulation_name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:normal,maturity'],
        ]);

        $template = HoldingAssessmentTemplate::create([
            ...$data,
            'type' => $data['type'] ?? 'normal',
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, 'holding_assessment.template', $template->id, 'create', ['name' => $template->name]);

        return response()->json(['data' => $template], 201);
    }

    public function updateTemplate(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'regulation_code' => ['nullable', 'string', 'max:40'],
            'regulation_name' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:normal,maturity'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);

        $template->update($data);
        $this->audit($request, 'holding_assessment.template', $template->id, 'update', $data);

        return response()->json(['data' => $template]);
    }

    public function destroyTemplate(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::findOrFail($id);
        $template->delete();
        $this->audit($request, 'holding_assessment.template', $id, 'delete', []);

        return response()->json(['message' => 'Template dipindahkan ke sampah.']);
    }

    /**
     * Pulihkan template yang sudah di-soft-delete.
     */
    public function restoreTemplate(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::onlyTrashed()->findOrFail($id);
        $template->restore();
        $this->audit($request, 'holding_assessment.template', $id, 'restore', []);

        return response()->json(['message' => 'Template dipulihkan.', 'data' => $template]);
    }

    /**
     * Hapus permanen template (beserta pertanyaannya) dari sampah.
     */
    public function forceDeleteTemplate(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::withTrashed()->findOrFail($id);
        // Hapus pertanyaan terkait permanen juga (force) agar tidak menggantung.
        HoldingAssessmentQuestion::withTrashed()->where('template_id', $template->id)->forceDelete();
        $template->forceDelete();
        $this->audit($request, 'holding_assessment.template', $id, 'force_delete', []);

        return response()->json(['message' => 'Template dihapus permanen.']);
    }

    // ===================================================================
    // QUESTIONS (nested under template)
    // ===================================================================

    public function storeQuestion(Request $request, string $templateId)
    {
        $this->resolveHolding($request);

        $template = HoldingAssessmentTemplate::findOrFail($templateId);
        $data = $this->validateQuestion($request);

        $data['template_id'] = $template->id;
        $data['org_id'] = $template->org_id;
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) (HoldingAssessmentQuestion::where('template_id', $template->id)->max('sort_order') ?? 0) + 1;
        }

        $q = HoldingAssessmentQuestion::create($data);
        $this->audit($request, 'holding_assessment.question', $q->id, 'create', ['template_id' => $template->id]);

        return response()->json(['data' => $q], 201);
    }

    public function updateQuestion(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $q = HoldingAssessmentQuestion::findOrFail($id);
        $data = $this->validateQuestion($request, true);
        $q->update($data);
        $this->audit($request, 'holding_assessment.question', $q->id, 'update', $data);

        return response()->json(['data' => $q]);
    }

    public function destroyQuestion(Request $request, string $id)
    {
        $this->resolveHolding($request);

        $q = HoldingAssessmentQuestion::findOrFail($id);
        $q->delete();
        $this->audit($request, 'holding_assessment.question', $id, 'delete', []);

        return response()->json(['message' => 'Pertanyaan dihapus.']);
    }

    /**
     * Reorder pertanyaan dalam satu template. Body: { order: [questionId, ...] }.
     */
    public function reorderQuestions(Request $request, string $templateId)
    {
        $this->resolveHolding($request);
        $template = HoldingAssessmentTemplate::findOrFail($templateId);

        $order = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['string'],
        ])['order'];

        foreach ($order as $i => $qid) {
            HoldingAssessmentQuestion::where('template_id', $template->id)
                ->where('id', $qid)
                ->update(['sort_order' => $i + 1]);
        }

        return response()->json(['message' => 'Urutan diperbarui.']);
    }

    private function validateQuestion(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category' => [$req, 'string', 'max:255'],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'question_code' => ['nullable', 'string', 'max:60'],
            'question' => [$req, 'string'],
            'explanation' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'regulation_ref' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'requires_evidence' => ['nullable', 'boolean'],
            'answer_type' => ['nullable', 'in:yes_partial_no,yes_no,text,choice'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    // ===================================================================
    // DISPATCH — kirim template ke anak perusahaan / sub-holding
    // ===================================================================

    /**
     * Buat instance + token publik untuk tiap target org. Pertanyaan dibekukan
     * (snapshot) saat dispatch. Target wajib di dalam descendant holding.
     *
     * Body: { template_id, target_org_ids: [uuid,...], expiry_days?: int }
     */
    public function dispatch(Request $request)
    {
        [$holding, $descendantIds] = $this->resolveHolding($request);

        $data = $request->validate([
            'template_id' => ['required', 'string'],
            'target_org_ids' => ['required', 'array', 'min:1'],
            'target_org_ids.*' => ['string'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $template = HoldingAssessmentTemplate::with('activeQuestions')->findOrFail($data['template_id']);

        // Validasi: semua target harus descendant holding (cegah cross-tenant leak).
        $invalid = array_diff($data['target_org_ids'], $descendantIds);
        if (! empty($invalid)) {
            return response()->json([
                'message' => 'Sebagian target bukan anak perusahaan/sub-holding Anda.',
                'invalid_org_ids' => array_values($invalid),
            ], 422);
        }

        $snapshot = $template->activeQuestions->map(fn ($q) => $q->toSnapshot())->all();
        if (empty($snapshot)) {
            return response()->json(['message' => 'Template belum punya pertanyaan aktif.'], 422);
        }

        $expiryDays = $data['expiry_days'] ?? 30;
        $ownerOrgId = $holding?->id ?? $request->user()->org_id;
        $created = [];

        DB::transaction(function () use ($data, $template, $snapshot, $expiryDays, $ownerOrgId, $request, &$created) {
            $targets = Organization::whereIn('id', $data['target_org_ids'])->get()->keyBy('id');

            foreach ($data['target_org_ids'] as $targetId) {
                $target = $targets->get($targetId);
                $instance = HoldingAssessmentInstance::create([
                    'org_id' => $ownerOrgId,
                    'template_id' => $template->id,
                    'source_org_id' => $ownerOrgId,
                    'target_org_id' => $targetId,
                    'target_org_name' => $target?->name,
                    'title' => $template->name,
                    'regulation_code' => $template->regulation_code,
                    'regulation_name' => $template->regulation_name,
                    'type' => $template->type ?? 'normal',
                    'questions_snapshot' => $snapshot,
                    'answers' => [],
                    'status' => 'sent',
                    'assessment_token' => (string) Str::uuid7(),
                    'token_expires_at' => now()->addDays($expiryDays),
                    'dispatched_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
                $created[] = [
                    'instance_id' => $instance->id,
                    'target_org_id' => $targetId,
                    'target_org_name' => $target?->name,
                    'token' => $instance->assessment_token,
                ];
            }
        });

        $this->audit($request, 'holding_assessment.dispatch', $template->id, 'dispatch', [
            'count' => count($created),
            'target_org_ids' => $data['target_org_ids'],
        ]);

        return response()->json([
            'message' => count($created).' assessment dikirim.',
            'data' => $created,
        ], 201);
    }

    // ===================================================================
    // INSTANCES — monitoring oleh holding
    // ===================================================================

    public function indexInstances(Request $request)
    {
        $this->resolveHolding($request);

        $instances = HoldingAssessmentInstance::query()
            ->when($request->query('template_id'), fn ($q, $v) => $q->where('template_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($i) => $this->instanceSummary($i));

        return response()->json(['data' => $instances]);
    }

    public function showInstance(Request $request, string $id)
    {
        $this->resolveHolding($request);
        $instance = HoldingAssessmentInstance::findOrFail($id);

        return response()->json(['data' => $this->instanceDetail($instance)]);
    }

    /**
     * Regenerate token (mis. link kedaluwarsa / hilang). Reset consumed.
     */
    public function regenerateToken(Request $request, string $id)
    {
        $this->resolveHolding($request);
        $instance = HoldingAssessmentInstance::findOrFail($id);

        $expiryDays = (int) ($request->input('expiry_days', 30));
        $instance->forceFill([
            'assessment_token' => (string) Str::uuid7(),
            'token_expires_at' => now()->addDays($expiryDays > 0 ? $expiryDays : 30),
            'token_consumed_at' => null,
            'status' => $instance->status === 'sent' ? 'sent' : 'sent',
        ])->save();

        $this->audit($request, 'holding_assessment.instance', $instance->id, 'regenerate_token', []);

        return response()->json(['data' => ['token' => $instance->assessment_token, 'token_expires_at' => $instance->token_expires_at]]);
    }

    /**
     * F6 — Grafik kepatuhan anak & cucu perusahaan. Skor terakhir per target org
     * (instance yang sudah submit/approve), dilengkapi tier hierarki:
     *   - 'anak' : org yang parent_id-nya = holding (anak langsung)
     *   - 'cucu' : org lebih dalam (parent bukan holding)
     * Bisa difilter ?template_id / ?type (normal|maturity) / ?regulation_code.
     */
    public function complianceGraph(Request $request)
    {
        [$holding, $descendantIds] = $this->resolveHolding($request);
        $holdingId = $holding?->id;

        $rows = HoldingAssessmentInstance::query()
            ->whereIn('status', ['submitted', 'review_in_progress', 'approved', 'rejected'])
            ->whereNotNull('overall_score')
            ->when($request->query('template_id'), fn ($q, $v) => $q->where('template_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('regulation_code'), fn ($q, $v) => $q->where('regulation_code', $v))
            ->orderByDesc('submitted_at')
            ->get();

        // Skor terakhir per target org (instance pertama setelah sort desc).
        $latestByOrg = [];
        foreach ($rows as $r) {
            $key = $r->target_org_id ?? $r->id;
            if (! isset($latestByOrg[$key])) {
                $latestByOrg[$key] = $r;
            }
        }

        $orgs = Organization::whereIn('id', $descendantIds)->get()->keyBy('id');

        $data = [];
        foreach ($latestByOrg as $r) {
            $org = $r->target_org_id ? $orgs->get($r->target_org_id) : null;
            $tier = ($org && $org->parent_id === $holdingId) ? 'anak' : 'cucu';
            $data[] = [
                'target_org_id' => $r->target_org_id,
                'target_org_name' => $r->target_org_name ?? $org?->name,
                'org_level' => $org?->org_level,
                'tier' => $tier,
                'type' => $r->type ?? 'normal',
                'regulation_code' => $r->regulation_code,
                'regulation_name' => $r->regulation_name,
                'overall_score' => (float) $r->overall_score,
                'compliance_level' => $r->compliance_level,
                'maturity_level' => $r->maturity_level,
                'status' => $r->status,
                'submitted_at' => optional($r->submitted_at)->toIso8601String(),
            ];
        }

        // Ringkasan rata-rata per tier (untuk header chart).
        $byTier = collect($data)->groupBy('tier')->map(fn ($items) => [
            'count' => $items->count(),
            'avg_score' => round($items->avg('overall_score'), 1),
        ]);

        return response()->json([
            'data' => $data,
            'summary' => [
                'total' => count($data),
                'avg_score' => count($data) ? round(collect($data)->avg('overall_score'), 1) : null,
                'by_tier' => $byTier,
            ],
        ]);
    }

    private function instanceSummary(HoldingAssessmentInstance $i): array
    {
        return [
            'id' => $i->id,
            'title' => $i->title,
            'target_org_id' => $i->target_org_id,
            'target_org_name' => $i->target_org_name,
            'regulation_code' => $i->regulation_code,
            'type' => $i->type ?? 'normal',
            'status' => $i->status,
            'overall_score' => $i->overall_score !== null ? (float) $i->overall_score : null,
            'compliance_level' => $i->compliance_level,
            'maturity_level' => $i->maturity_level,
            'progress' => (float) $i->progress,
            'submitted_at' => $i->submitted_at,
            'token_expires_at' => $i->token_expires_at,
            'is_locked' => $i->isLocked(),
            'is_expired' => $i->isExpired(),
            'dispatched_at' => $i->dispatched_at,
            // Token di-expose eksplisit (model meng-hide dari serialisasi default)
            // supaya holding bisa menyalin tautan publik untuk dibagikan.
            'token' => $i->assessment_token,
        ];
    }

    /**
     * Daftar org tujuan dispatch: descendant holding (tanpa holding itu sendiri).
     */
    public function dispatchTargets(Request $request)
    {
        [$holding, $descendantIds] = $this->resolveHolding($request);
        $selfId = $holding?->id;

        $targets = Organization::query()
            ->whereIn('id', $descendantIds)
            ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))
            ->orderBy('org_level')
            ->orderBy('name')
            ->get(['id', 'name', 'org_level', 'parent_id'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'org_level' => $o->org_level,
                'parent_id' => $o->parent_id,
            ]);

        return response()->json(['data' => $targets]);
    }

    private function instanceDetail(HoldingAssessmentInstance $i): array
    {
        return array_merge($this->instanceSummary($i), [
            'questions' => $i->effectiveQuestions(),
            'answers' => $i->answers ?? [],
            'ai_analyses' => $i->ai_analyses ?? [],
            'review_status' => $i->review_status,
            'review_notes' => $i->review_notes,
        ]);
    }

    private function audit(Request $request, string $module, string $recordId, string $action, array $changes): void
    {
        AuditLog::create([
            'module' => $module,
            'record_id' => $recordId,
            'action' => $action,
            'user_id' => $request->user()->id ?? null,
            'user_name' => $request->user()->name ?? null,
            'user_role' => $request->user()->role ?? null,
            'section' => 'holding_assessment',
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
