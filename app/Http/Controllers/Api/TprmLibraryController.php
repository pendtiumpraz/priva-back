<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionLibrary;
use App\Models\QuestionLibrarySegment;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TPRM Phase 1 — Question Library + Segment CRUD.
 *
 * Resource hierarchy:
 *   library              -> 1 set questionnaire utuh untuk satu use case
 *   library.segments     -> domain/section dalam library (Tata Kelola, dll)
 *   library.questions    -> via segment, di-handle ThirdPartyQuestionController
 *
 * Akses:
 *   - Template global (org_id NULL, is_locked=true): readable by all tenant,
 *     editable hanya superadmin. Tenant clone -> bikin copy private.
 *   - Library tenant (org_id = X): editable by org X admin/DPO.
 *
 * Permission slug: `vendor_risk` (modul TPRM tidak punya slug terpisah).
 */
class TprmLibraryController extends Controller
{
    // =============================================
    // LIBRARY CRUD
    // =============================================

    /**
     * GET /api/tprm/libraries
     *
     * List library visible untuk tenant (template global + own libraries).
     * Filter: ?include_inactive=1 untuk include yang dihapus/non-aktif.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->visibleTo($orgId);

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $libraries = $query
            ->orderByDesc('is_locked')           // template global di atas
            ->orderBy('name')
            ->get();

        // Shadowing COW: fork org (source=forked) MENGGANTIKAN template
        // platform asalnya — org melihat SATU entri per template: fork-nya
        // kalau ada, else template platform asli.
        $shadowedTemplateIds = $libraries
            ->filter(fn ($l) => $l->org_id === $orgId
                && $l->source === QuestionLibrary::SOURCE_FORKED
                && $l->cloned_from_library_id)
            ->pluck('cloned_from_library_id')
            ->all();

        $libraries = $libraries
            ->reject(fn ($l) => $l->org_id === null && in_array($l->id, $shadowedTemplateIds, true))
            ->values()
            ->map(fn ($lib) => $this->presentLibrary($lib, $orgId));

        return response()->json(['data' => $libraries]);
    }

    /**
     * GET /api/tprm/libraries/{id}
     *
     * Detail satu library + segments + count questions per segment.
     * Tenant hanya boleh akses template atau own library.
     */
    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findVisibleOrFail($id, $orgId);

        $segments = $library->segments()
            ->orderBy('order_index')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'description' => $s->description,
                'order_index' => $s->order_index,
                'weight_pct' => $s->weight_pct,
                'questions_count' => $s->questions_count,
            ]);

        return response()->json([
            'data' => array_merge(
                $this->presentLibrary($library, $orgId),
                ['segments' => $segments]
            ),
        ]);
    }

    /**
     * POST /api/tprm/libraries
     *
     * Create library kosong milik tenant. Mulai dari blank — segment +
     * question ditambah lewat endpoint terpisah.
     */
    public function store(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'Org context required.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'category' => 'nullable|string|max:64',
            'version' => 'nullable|string|max:16',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:32',
        ]);

        $library = QuestionLibrary::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'version' => $data['version'] ?? 'v1',
            'source' => QuestionLibrary::SOURCE_CUSTOM,
            'is_active' => true,
            'is_locked' => false,
            'tags' => $data['tags'] ?? [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->presentLibrary($library, $orgId),
        ], 201);
    }

    /**
     * POST /api/tprm/libraries/snapshot
     *
     * "Simpan sebagai Template" — snapshot set pertanyaan EFEKTIF org saat ini
     * (VendorQuestionnaire::effectiveForOrg = default + override + custom dari
     * Kelola Pertanyaan) menjadi library baru milik org. Segment dibentuk
     * otomatis dari `section` pertanyaan (Tata Kelola, Keamanan, dst).
     *
     * Versi auto: "v{N}" dengan N = jumlah snapshot org yang sudah ada + 1,
     * supaya snapshot berulang tidak bentrok nama/versi.
     *
     * Hasilnya bisa dipilih sebagai bank pertanyaan saat membuat tautan
     * asesmen pihak ketiga (library_id pada vendor_assessments), dan immune
     * terhadap perubahan Kelola Pertanyaan berikutnya (copy mandiri).
     */
    public function snapshotFromEffective(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'Org context required.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
        ]);

        // Set efektif org — hanya pertanyaan aktif versi scorer (v2_2026).
        $effective = VendorQuestionnaire::effectiveForOrg($orgId)
            ->filter(fn ($q) => $q->is_active && $q->version === 'v2_2026')
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']])
            ->values();

        if ($effective->isEmpty()) {
            return response()->json([
                'error' => 'Set pertanyaan efektif organisasi kosong — tidak ada yang bisa disimpan sebagai template.',
            ], 422);
        }

        // Auto-version: v{N} per (org, nama) — termasuk yang sudah dihapus
        // supaya versi tidak pernah reuse saat snapshot berulang nama sama.
        $sameName = QuestionLibrary::withTrashed()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('name', $data['name'])
            ->count();
        $version = 'v'.($sameName + 1);

        $library = DB::transaction(function () use ($orgId, $data, $effective, $version, $request) {
            $lib = QuestionLibrary::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                'description' => $data['description']
                    ?? 'Snapshot set pertanyaan efektif organisasi (Kelola Pertanyaan).',
                'category' => 'pdp_compliance',
                'version' => $version,
                'source' => QuestionLibrary::SOURCE_CUSTOM,
                'is_active' => true,
                'is_locked' => false,
                'tags' => ['snapshot'],
                'created_by' => $request->user()->id,
            ]);

            // Segment per section, urut sesuai kemunculan di set efektif.
            $segmentMap = [];
            $orderIndex = 0;
            foreach ($effective as $q) {
                $sectionKey = $q->section ?: 'lainnya';
                if (isset($segmentMap[$sectionKey])) {
                    continue;
                }
                // 'id' tidak fillable — HasUuids generate sendiri. Baca id
                // dari model hasil create() supaya link question→segment valid.
                $seg = QuestionLibrarySegment::create([
                    'library_id' => $lib->id,
                    'name' => VendorQuestionnaire::SECTION_LABELS[$sectionKey] ?? ucwords(str_replace('_', ' ', $sectionKey)),
                    'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $sectionKey), 0, 3)),
                    'order_index' => $orderIndex++,
                    'weight_pct' => 0,
                ]);
                $segmentMap[$sectionKey] = $seg->id;
            }

            // Copy mandiri tiap pertanyaan (bukan reference) — perubahan
            // Kelola Pertanyaan berikutnya tidak mengubah template ini.
            foreach ($effective as $q) {
                VendorQuestionnaire::create([
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'parent_id' => null,
                    'library_id' => $lib->id,
                    'library_segment_id' => $segmentMap[$q->section ?: 'lainnya'],
                    'category' => $q->category,
                    'version' => $version,
                    'question_code' => $q->question_code,
                    'section' => $q->section,
                    'question_text' => $q->question_text,
                    'description' => $q->description,
                    'regulation_ref' => $q->regulation_ref,
                    'recommendation_if_no' => $q->recommendation_if_no,
                    'requires_evidence_upload' => $q->requires_evidence_upload,
                    'answer_type' => $q->answer_type,
                    'answer_options' => $q->answer_options,
                    'weight' => $q->weight,
                    'direction' => $q->direction,
                    'is_active' => true,
                    'sort_order' => $q->sort_order,
                ]);
            }

            $lib->refreshCounters();
            foreach ($lib->segments as $seg) {
                $seg->refreshCounter();
            }

            return $lib;
        });

        return response()->json([
            'message' => "Template \"{$library->name}\" ({$version}) berhasil dibuat dari {$effective->count()} pertanyaan efektif organisasi.",
            'questions_count' => $effective->count(),
            'data' => $this->presentLibrary($library->fresh(), $orgId),
        ], 201);
    }

    /**
     * POST /api/tprm/libraries/{id}/clone
     *
     * Duplikat library + semua segment + semua question ke library baru
     * milik tenant. Cara utama tenant mengambil template global lalu
     * mengubahnya sesuai kebijakan internal.
     */
    public function clone(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'Org context required.'], 403);
        }

        $source = $this->findVisibleOrFail($id, $orgId);

        $data = $request->validate([
            'name' => 'nullable|string|max:200',
        ]);

        [$newLibrary] = $this->duplicateLibrary(
            $source,
            $orgId,
            $request->user()->id,
            $data['name'] ?? ($source->name.' (Salinan)'),
            QuestionLibrary::SOURCE_CLONED,
        );

        return response()->json([
            'data' => $this->presentLibrary($newLibrary->fresh(), $orgId),
        ], 201);
    }

    /**
     * Copy library + semua segment + semua question ke library baru milik
     * tenant. Dipakai clone() (manual "Salin") dan COW fork (edit template
     * platform via Bank Pertanyaan).
     *
     * @return array{0: QuestionLibrary, 1: array<string,string>, 2: array<string,string>}
     *                                                                                     [library baru, map old_segment_id=>new_segment_id, map old_question_id=>new_question_id]
     */
    private function duplicateLibrary(
        QuestionLibrary $source,
        string $orgId,
        string $userId,
        string $name,
        string $sourceType
    ): array {
        return DB::transaction(function () use ($source, $orgId, $userId, $name, $sourceType) {
            $lib = QuestionLibrary::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                'description' => $source->description,
                'category' => $source->category,
                'version' => $source->version,
                'source' => $sourceType,
                'is_active' => true,
                'is_locked' => false,
                'tags' => $source->tags,
                'created_by' => $userId,
                'cloned_from_library_id' => $source->id,
            ]);

            // Map old_segment_id -> new_segment_id supaya question link tepat.
            // CATATAN: 'id' TIDAK fillable di model ini — HasUuids generate id
            // sendiri saat creating. Selalu baca id dari model hasil create(),
            // jangan pre-generate uuid lalu asumsikan terpakai.
            $segmentMap = [];
            foreach ($source->segments as $oldSeg) {
                $newSeg = QuestionLibrarySegment::create([
                    'library_id' => $lib->id,
                    'name' => $oldSeg->name,
                    'code' => $oldSeg->code,
                    'description' => $oldSeg->description,
                    'order_index' => $oldSeg->order_index,
                    'weight_pct' => $oldSeg->weight_pct,
                ]);
                $segmentMap[$oldSeg->id] = $newSeg->id;
            }

            // Clone semua question — buat copy baru bukan reference parent,
            // supaya tenant bisa edit tanpa lihat perubahan template global.
            $oldQuestions = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $source->id)
                ->when($source->org_id, fn ($q) => $q->where('org_id', $source->org_id))
                ->when(! $source->org_id, fn ($q) => $q->whereNull('org_id'))
                ->get();

            $questionMap = [];
            foreach ($oldQuestions as $oldQ) {
                $newSegId = $segmentMap[$oldQ->library_segment_id] ?? null;
                $newQ = VendorQuestionnaire::create([
                    'org_id' => $orgId,
                    'parent_id' => null, // copy mandiri, bukan override
                    'library_id' => $lib->id,
                    'library_segment_id' => $newSegId,
                    'category' => $oldQ->category,
                    'version' => $lib->version,
                    'question_code' => $oldQ->question_code,
                    'section' => $oldQ->section,
                    'question_text' => $oldQ->question_text,
                    'description' => $oldQ->description,
                    'regulation_ref' => $oldQ->regulation_ref,
                    'answer_type' => $oldQ->answer_type,
                    'answer_options' => $oldQ->answer_options,
                    'weight' => $oldQ->weight,
                    'direction' => $oldQ->direction,
                    'recommendation_if_no' => $oldQ->recommendation_if_no,
                    'requires_evidence_upload' => $oldQ->requires_evidence_upload,
                    'is_active' => $oldQ->is_active,
                    'sort_order' => $oldQ->sort_order,
                ]);
                $questionMap[$oldQ->id] = $newQ->id;
            }

            $lib->refreshCounters();
            foreach ($lib->segments as $seg) {
                $seg->refreshCounter();
            }

            return [$lib, $segmentMap, $questionMap];
        });
    }

    /**
     * PATCH /api/tprm/libraries/{id}
     * Update meta library (name, description, tags, is_active).
     * Template platform → copy-on-write fork otomatis (lihat resolveEditable).
     */
    public function update(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'sometimes|nullable|string|max:2000',
            'category' => 'sometimes|nullable|string|max:64',
            'version' => 'sometimes|string|max:16',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:32',
            'is_active' => 'sometimes|boolean',
        ]);

        $library->fill($data)->save();

        return response()->json(
            ['data' => $this->presentLibrary($library->fresh(), $orgId)] + $this->cowMeta($library, $orgId)
        );
    }

    /**
     * DELETE /api/tprm/libraries/{id}
     * Soft-delete library (semua segment + question masih ada di DB).
     * Template global (is_locked) tidak bisa dihapus.
     */
    public function destroy(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $library->delete();

        return response()->json(['message' => 'Library dihapus.']);
    }

    // =============================================
    // SEGMENT CRUD (nested under library)
    // =============================================

    /**
     * POST /api/tprm/libraries/{id}/segments
     */
    public function storeSegment(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:1000',
            'order_index' => 'nullable|integer|min:0|max:9999',
            'weight_pct' => 'nullable|integer|min:0|max:100',
        ]);

        $segment = QuestionLibrarySegment::create([
            'id' => (string) Str::uuid(),
            'library_id' => $library->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'order_index' => $data['order_index'] ?? ($library->segments()->max('order_index') + 1),
            'weight_pct' => $data['weight_pct'] ?? 0,
        ]);

        $library->refreshCounters();

        return response()->json(['data' => $segment] + $this->cowMeta($library, $orgId), 201);
    }

    /**
     * PATCH /api/tprm/libraries/{id}/segments/{segmentId}
     */
    public function updateSegment(Request $request, string $id, string $segmentId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $segment = QuestionLibrarySegment::query()
            ->where('id', $this->mapSegmentId($segmentId))
            ->where('library_id', $library->id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'code' => 'sometimes|nullable|string|max:16',
            'description' => 'sometimes|nullable|string|max:1000',
            'order_index' => 'sometimes|integer|min:0|max:9999',
            'weight_pct' => 'sometimes|integer|min:0|max:100',
        ]);

        $segment->fill($data)->save();

        return response()->json(['data' => $segment->fresh()] + $this->cowMeta($library, $orgId));
    }

    /**
     * DELETE /api/tprm/libraries/{id}/segments/{segmentId}
     * Soft-delete segment. Question di dalamnya tidak di-cascade — di-orphan
     * (library_segment_id tetap punya nilai, tapi join akan ke null). Caller
     * disarankan move-then-delete via UI.
     */
    public function destroySegment(Request $request, string $id, string $segmentId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $segment = QuestionLibrarySegment::query()
            ->where('id', $this->mapSegmentId($segmentId))
            ->where('library_id', $library->id)
            ->firstOrFail();

        $segment->delete();
        $library->refreshCounters();

        return response()->json(['message' => 'Segment dihapus.'] + $this->cowMeta($library, $orgId));
    }

    /**
     * POST /api/tprm/libraries/{id}/segments/reorder
     * Body: { "order": ["segId1", "segId2", "segId3"] }
     * Bulk reorder via array order — lebih simple daripada per-segment update.
     */
    public function reorderSegments(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|string',
        ]);

        DB::transaction(function () use ($library, $data) {
            foreach ($data['order'] as $i => $segId) {
                QuestionLibrarySegment::query()
                    ->where('id', $this->mapSegmentId($segId))
                    ->where('library_id', $library->id)
                    ->update(['order_index' => $i]);
            }
        });

        return response()->json(['message' => 'Urutan segment diperbarui.'] + $this->cowMeta($library, $orgId));
    }

    // =============================================
    // QUESTION CRUD (di dalam library + segment)
    // =============================================

    /**
     * POST /api/tprm/libraries/{id}/questions
     *
     * Tambah pertanyaan baru ke library. Wajib spesifikkan segment_id.
     * Tenant ONLY (template global locked) — sudah di-guard findOwnedOrFail.
     */
    public function storeQuestion(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'library_segment_id' => 'required|string',
            'question_code' => 'nullable|string|max:16',
            'section' => 'nullable|string|max:64',
            'question_text' => 'required|string|max:2000',
            'description' => 'nullable|string|max:2000',
            'regulation_ref' => 'nullable|string|max:255',
            'recommendation_if_no' => 'nullable|string|max:2000',
            'requires_evidence_upload' => 'nullable|boolean',
            'answer_type' => 'required|in:yes_no,multi_choice,scale_1_5',
            'answer_options' => 'nullable|array',
            'weight' => 'nullable|integer|min:1|max:10',
            'direction' => 'nullable|integer|in:-1,1',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Verify segment milik library yang sama (anti cross-library injection)
        $segment = QuestionLibrarySegment::query()
            ->where('id', $this->mapSegmentId($data['library_segment_id']))
            ->where('library_id', $library->id)
            ->firstOrFail();

        $question = VendorQuestionnaire::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'parent_id' => null,
            'library_id' => $library->id,
            'library_segment_id' => $segment->id,
            'category' => $library->category ?? 'custom',
            'version' => $library->version,
            'question_code' => $data['question_code'] ?? $this->nextQuestionCode($library, $segment),
            'section' => $data['section'] ?? $segment->code,
            'question_text' => $data['question_text'],
            'description' => $data['description'] ?? null,
            'regulation_ref' => $data['regulation_ref'] ?? null,
            'recommendation_if_no' => $data['recommendation_if_no'] ?? null,
            'requires_evidence_upload' => $data['requires_evidence_upload'] ?? false,
            'answer_type' => $data['answer_type'],
            'answer_options' => $data['answer_options'] ?? null,
            'weight' => $data['weight'] ?? 5,
            'direction' => $data['direction'] ?? 1,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ($segment->questions()->max('sort_order') + 1),
        ]);

        $library->refreshCounters();
        $segment->refreshCounter();

        return response()->json(['data' => $question] + $this->cowMeta($library, $orgId), 201);
    }

    /**
     * PATCH /api/tprm/libraries/{id}/questions/{questionId}
     */
    public function updateQuestion(Request $request, string $id, string $questionId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $question = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $this->mapQuestionId($questionId))
            ->where('library_id', $library->id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        $data = $request->validate([
            'library_segment_id' => 'sometimes|string',
            'question_code' => 'sometimes|nullable|string|max:16',
            'question_text' => 'sometimes|string|max:2000',
            'description' => 'sometimes|nullable|string|max:2000',
            'regulation_ref' => 'sometimes|nullable|string|max:255',
            'recommendation_if_no' => 'sometimes|nullable|string|max:2000',
            'requires_evidence_upload' => 'sometimes|boolean',
            'answer_type' => 'sometimes|in:yes_no,multi_choice,scale_1_5',
            'answer_options' => 'sometimes|nullable|array',
            'weight' => 'sometimes|integer|min:1|max:10',
            'direction' => 'sometimes|integer|in:-1,1',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        // Validate segment pindah masih di library yang sama
        if (isset($data['library_segment_id'])) {
            $data['library_segment_id'] = $this->mapSegmentId($data['library_segment_id']);
            QuestionLibrarySegment::query()
                ->where('id', $data['library_segment_id'])
                ->where('library_id', $library->id)
                ->firstOrFail();
        }

        $question->fill($data)->save();

        // Refresh counter kalau segment berubah (lama + baru perlu update)
        if (isset($data['library_segment_id'])) {
            foreach ($library->segments as $seg) {
                $seg->refreshCounter();
            }
        }

        return response()->json(['data' => $question->fresh()] + $this->cowMeta($library, $orgId));
    }

    /**
     * DELETE /api/tprm/libraries/{id}/questions/{questionId}
     */
    public function destroyQuestion(Request $request, string $id, string $questionId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $question = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $this->mapQuestionId($questionId))
            ->where('library_id', $library->id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        $segmentId = $question->library_segment_id;
        $question->delete();

        $library->refreshCounters();
        if ($segmentId) {
            $seg = QuestionLibrarySegment::find($segmentId);
            $seg?->refreshCounter();
        }

        return response()->json(['message' => 'Pertanyaan dihapus.'] + $this->cowMeta($library, $orgId));
    }

    /**
     * POST /api/tprm/libraries/{id}/questions/reorder
     * Body: { "order": ["qId1", "qId2", "qId3"] }
     */
    public function reorderQuestions(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|string',
        ]);

        DB::transaction(function () use ($library, $orgId, $data) {
            foreach ($data['order'] as $i => $qId) {
                VendorQuestionnaire::query()
                    ->withoutGlobalScope('org')
                    ->where('id', $this->mapQuestionId($qId))
                    ->where('library_id', $library->id)
                    ->where('org_id', $orgId)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['message' => 'Urutan pertanyaan diperbarui.'] + $this->cowMeta($library, $orgId));
    }

    /**
     * GET /api/tprm/libraries/{id}/questions
     * Flat list semua pertanyaan di library (group-by segment di FE).
     */
    public function listQuestions(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findVisibleOrFail($id, $orgId);

        $questions = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('library_id', $library->id)
            ->when($library->org_id, fn ($q) => $q->where('org_id', $library->org_id))
            ->when(! $library->org_id, fn ($q) => $q->whereNull('org_id'))
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    /**
     * POST /api/tprm/libraries/{id}/questions/bulk
     * Body: { "questions": [{...}, {...}, ...] }
     *
     * Bulk insert untuk migrate ratusan soal dari CSV/Excel. Tiap entry
     * wajib punya library_segment_id valid milik library ini.
     */
    public function bulkStoreQuestions(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->resolveEditable($request, $id);

        $data = $request->validate([
            'questions' => 'required|array|min:1|max:500',
            'questions.*.library_segment_id' => 'required|string',
            'questions.*.question_text' => 'required|string|max:2000',
            'questions.*.answer_type' => 'required|in:yes_no,multi_choice,scale_1_5',
            'questions.*.question_code' => 'nullable|string|max:16',
            'questions.*.section' => 'nullable|string|max:64',
            'questions.*.description' => 'nullable|string|max:2000',
            'questions.*.regulation_ref' => 'nullable|string|max:255',
            'questions.*.recommendation_if_no' => 'nullable|string|max:2000',
            'questions.*.requires_evidence_upload' => 'nullable|boolean',
            'questions.*.answer_options' => 'nullable|array',
            'questions.*.weight' => 'nullable|integer|min:1|max:10',
            'questions.*.direction' => 'nullable|integer|in:-1,1',
        ]);

        // Terjemahkan id segment (mungkin milik template platform saat COW)
        foreach ($data['questions'] as $i => $q) {
            $data['questions'][$i]['library_segment_id'] = $this->mapSegmentId($q['library_segment_id']);
        }

        // Validate semua segment milik library yang sama
        $segmentIds = collect($data['questions'])->pluck('library_segment_id')->unique();
        $validSegments = QuestionLibrarySegment::query()
            ->where('library_id', $library->id)
            ->whereIn('id', $segmentIds)
            ->pluck('id', 'id')
            ->toArray();

        $invalid = $segmentIds->reject(fn ($sId) => isset($validSegments[$sId]))->values();
        if ($invalid->isNotEmpty()) {
            return response()->json([
                'error' => 'Beberapa library_segment_id tidak valid untuk library ini.',
                'invalid_segment_ids' => $invalid,
            ], 422);
        }

        $now = now();
        $inserted = DB::transaction(function () use ($library, $orgId, $data) {
            $rows = [];
            $baseOrder = (VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $library->id)
                ->max('sort_order') ?? -1) + 1;

            foreach ($data['questions'] as $i => $q) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'parent_id' => null,
                    'library_id' => $library->id,
                    'library_segment_id' => $q['library_segment_id'],
                    'category' => $library->category ?? 'custom',
                    'version' => $library->version,
                    'question_code' => $q['question_code'] ?? null,
                    'section' => $q['section'] ?? null,
                    'question_text' => $q['question_text'],
                    'description' => $q['description'] ?? null,
                    'regulation_ref' => $q['regulation_ref'] ?? null,
                    'recommendation_if_no' => $q['recommendation_if_no'] ?? null,
                    'requires_evidence_upload' => $q['requires_evidence_upload'] ?? false,
                    'answer_type' => $q['answer_type'],
                    'answer_options' => isset($q['answer_options']) ? json_encode($q['answer_options']) : null,
                    'weight' => $q['weight'] ?? 5,
                    'direction' => $q['direction'] ?? 1,
                    'is_active' => true,
                    'sort_order' => $baseOrder + $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            VendorQuestionnaire::insert($rows);

            return count($rows);
        });

        $library->refreshCounters();
        foreach ($library->segments as $seg) {
            $seg->refreshCounter();
        }

        return response()->json([
            'message' => "{$inserted} pertanyaan ditambahkan.",
            'inserted_count' => $inserted,
        ] + $this->cowMeta($library, $orgId), 201);
    }

    /**
     * Auto-generate question_code dari segment code + sequence number.
     * Mis. GOV-01, GOV-02, OPS-01.
     */
    private function nextQuestionCode(QuestionLibrary $library, QuestionLibrarySegment $segment): string
    {
        $prefix = $segment->code ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $segment->name), 0, 3));
        $count = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('library_id', $library->id)
            ->where('library_segment_id', $segment->id)
            ->count();

        return sprintf('%s-%02d', $prefix, $count + 1);
    }

    // =============================================
    // RESET KE DEFAULT (fork COW → kembali ke template platform)
    // =============================================

    /**
     * POST /api/tprm/libraries/{id}/reset-to-default
     *
     * {id} = fork COW milik org (source=forked). Fork di-SOFT-DELETE — bukan
     * hard delete — supaya asesmen in-flight yang ber-library_id fork tetap
     * bisa dirender & di-skor: jalur load pertanyaan (AsesmenPublikController,
     * TprmReviewController, ThirdPartyAssessmentScorer) query langsung
     * `vendor_questionnaires.library_id` tanpa join ke row library, dan
     * pertanyaan fork TIDAK dihapus. Setelah reset, template platform asli
     * muncul kembali pristine di list + picker (shadow hilang).
     */
    public function resetToDefault(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['error' => 'Org context required.'], 403);
        }

        $fork = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->where('source', QuestionLibrary::SOURCE_FORKED)
            ->firstOrFail();

        // Info untuk pesan konfirmasi — asesmen yang sudah terlanjur memakai
        // fork ini tetap menyimpan snapshot pertanyaannya (lihat docblock).
        $inFlightCount = VendorAssessment::query()
            ->where('org_id', $orgId)
            ->where('library_id', $fork->id)
            ->count();

        $template = $fork->cloned_from_library_id
            ? QuestionLibrary::query()
                ->withoutGlobalScope('org')
                ->whereNull('org_id')
                ->where('id', $fork->cloned_from_library_id)
                ->first()
            : null;

        $fork->delete(); // soft delete — pertanyaan fork tetap di DB

        return response()->json([
            'message' => $inFlightCount > 0
                ? "Template dikembalikan ke versi default platform. {$inFlightCount} asesmen yang sudah memakai versi ubahan tetap menggunakan snapshot pertanyaannya."
                : 'Template dikembalikan ke versi default platform.',
            'in_flight_assessments' => $inFlightCount,
            'data' => $template ? $this->presentLibrary($template, $orgId) : null,
        ]);
    }

    // =============================================
    // HELPERS
    // =============================================

    /**
     * Library yang visible (template global + own org). Throw 404 kalau tidak.
     */
    private function findVisibleOrFail(string $id, ?string $orgId): QuestionLibrary
    {
        return QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->visibleTo($orgId)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Library yang OWNED tenant (bukan template). Throw 403 kalau locked.
     * Dipakai untuk endpoint mutate (update, delete, add segment).
     */
    private function findOwnedOrFail(string $id, ?string $orgId): QuestionLibrary
    {
        if (! $orgId) {
            abort(403, 'Org context required.');
        }
        $library = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        if ($library->is_locked) {
            abort(403, 'Library terkunci. Clone dahulu untuk mengubah.');
        }

        return $library;
    }

    // ---------------------------------------------
    // Copy-on-write fork (Bank Pertanyaan = single surface)
    // ---------------------------------------------

    /** True kalau request ini di-redirect ke fork COW (target awal = template platform). */
    private bool $cowForked = false;

    /** Map id segment template platform → id segment fork. */
    private array $cowSegmentMap = [];

    /** Map id question template platform → id question fork. */
    private array $cowQuestionMap = [];

    /**
     * Resolve library yang BISA diedit org untuk operasi mutasi.
     *
     * - Library milik org (tidak locked) → dipakai langsung.
     * - Template platform (org_id NULL)  → copy-on-write: fork otomatis ke
     *   library milik org (source=forked, nama sama) yang men-shadow template
     *   di list/picker. Kalau fork sudah ada (mis. request dari tab lama yang
     *   masih memegang id template), operasi di-redirect ke fork existing
     *   dengan pemetaan id segment/question via code → name → text.
     *
     * Caller WAJIB menerjemahkan id segment/question dari payload lewat
     * mapSegmentId()/mapQuestionId() setelah memanggil method ini.
     */
    private function resolveEditable(Request $request, string $id): QuestionLibrary
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            abort(403, 'Org context required.');
        }

        $library = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->visibleTo($orgId)
            ->where('id', $id)
            ->firstOrFail();

        // Library milik org sendiri → edit langsung (locked tetap ditolak).
        if ($library->org_id === $orgId) {
            if ($library->is_locked) {
                abort(403, 'Library terkunci. Clone dahulu untuk mengubah.');
            }

            return $library;
        }

        // Template platform → COW fork.
        $existingFork = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('source', QuestionLibrary::SOURCE_FORKED)
            ->where('cloned_from_library_id', $library->id)
            ->orderByDesc('created_at')
            ->first();

        if ($existingFork) {
            $this->cowForked = true;
            $this->buildCowMaps($library, $existingFork);

            return $existingFork;
        }

        [$fork, $segmentMap, $questionMap] = $this->duplicateLibrary(
            $library,
            $orgId,
            $request->user()->id,
            $library->name,
            QuestionLibrary::SOURCE_FORKED,
        );

        $this->cowForked = true;
        $this->cowSegmentMap = $segmentMap;
        $this->cowQuestionMap = $questionMap;

        return $fork;
    }

    /**
     * Bangun map id template→fork untuk fork yang SUDAH ada (request masih
     * memegang id milik template platform). Match segment by code lalu name;
     * question by question_code lalu question_text.
     */
    private function buildCowMaps(QuestionLibrary $template, QuestionLibrary $fork): void
    {
        $forkSegments = $fork->segments;
        foreach ($template->segments as $tplSeg) {
            $match = $forkSegments->first(fn ($s) => $tplSeg->code && $s->code === $tplSeg->code)
                ?? $forkSegments->first(fn ($s) => $s->name === $tplSeg->name);
            if ($match) {
                $this->cowSegmentMap[$tplSeg->id] = $match->id;
            }
        }

        $tplQuestions = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('library_id', $template->id)
            ->whereNull('org_id')
            ->get();
        $forkQuestions = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('library_id', $fork->id)
            ->where('org_id', $fork->org_id)
            ->get();

        foreach ($tplQuestions as $tplQ) {
            $match = $forkQuestions->first(fn ($q) => $tplQ->question_code && $q->question_code === $tplQ->question_code)
                ?? $forkQuestions->first(fn ($q) => $q->question_text === $tplQ->question_text);
            if ($match) {
                $this->cowQuestionMap[$tplQ->id] = $match->id;
            }
        }
    }

    /** Terjemahkan id segment payload (mungkin milik template) ke id fork. */
    private function mapSegmentId(?string $segmentId): ?string
    {
        return $segmentId !== null ? ($this->cowSegmentMap[$segmentId] ?? $segmentId) : null;
    }

    /** Terjemahkan id question payload (mungkin milik template) ke id fork. */
    private function mapQuestionId(string $questionId): string
    {
        return $this->cowQuestionMap[$questionId] ?? $questionId;
    }

    /**
     * Metadata COW yang di-merge ke response mutasi: kalau request ini bekerja
     * pada fork (baru dibuat ATAU redirect ke fork existing), FE perlu pindah
     * ke library id fork. Empty array kalau bukan COW.
     */
    private function cowMeta(QuestionLibrary $library, ?string $orgId): array
    {
        if (! $this->cowForked) {
            return [];
        }

        return [
            'forked' => true,
            'library' => $this->presentLibrary($library->fresh(), $orgId),
        ];
    }

    private function presentLibrary(QuestionLibrary $lib, ?string $orgId): array
    {
        return [
            'id' => $lib->id,
            'org_id' => $lib->org_id,
            'name' => $lib->name,
            'slug' => $lib->slug,
            'description' => $lib->description,
            'category' => $lib->category,
            'version' => $lib->version,
            'source' => $lib->source,
            'is_active' => $lib->is_active,
            'is_locked' => $lib->is_locked,
            'is_template' => $lib->org_id === null,
            'is_owned' => $lib->org_id === $orgId,
            // Fork COW dari template platform — di UI tampil menggantikan
            // template asalnya dengan badge "(diubah)" + tombol Reset ke Default.
            'is_fork' => $lib->source === QuestionLibrary::SOURCE_FORKED,
            'segments_count' => $lib->segments_count,
            'questions_count' => $lib->questions_count,
            'tags' => $lib->tags ?? [],
            'cloned_from_library_id' => $lib->cloned_from_library_id,
            'created_at' => $lib->created_at?->toIso8601String(),
            'updated_at' => $lib->updated_at?->toIso8601String(),
        ];
    }
}
