<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionLibrary;
use App\Models\QuestionLibrarySegment;
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
            ->get()
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

        $newLibrary = DB::transaction(function () use ($source, $orgId, $data, $request) {
            $newLibId = (string) Str::uuid();

            $lib = QuestionLibrary::create([
                'id' => $newLibId,
                'org_id' => $orgId,
                'name' => $data['name'] ?? ($source->name.' (Salinan)'),
                'slug' => Str::slug(($data['name'] ?? $source->name)).'-'.Str::lower(Str::random(4)),
                'description' => $source->description,
                'category' => $source->category,
                'version' => $source->version,
                'source' => QuestionLibrary::SOURCE_CLONED,
                'is_active' => true,
                'is_locked' => false,
                'tags' => $source->tags,
                'created_by' => $request->user()->id,
                'cloned_from_library_id' => $source->id,
            ]);

            // Map old_segment_id -> new_segment_id supaya question link tepat
            $segmentMap = [];
            foreach ($source->segments as $oldSeg) {
                $newSegId = (string) Str::uuid();
                QuestionLibrarySegment::create([
                    'id' => $newSegId,
                    'library_id' => $lib->id,
                    'name' => $oldSeg->name,
                    'code' => $oldSeg->code,
                    'description' => $oldSeg->description,
                    'order_index' => $oldSeg->order_index,
                    'weight_pct' => $oldSeg->weight_pct,
                ]);
                $segmentMap[$oldSeg->id] = $newSegId;
            }

            // Clone semua question — buat copy baru bukan reference parent,
            // supaya tenant bisa edit tanpa lihat perubahan template global.
            $oldQuestions = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $source->id)
                ->get();

            foreach ($oldQuestions as $oldQ) {
                $newSegId = $segmentMap[$oldQ->library_segment_id] ?? null;
                VendorQuestionnaire::create([
                    'id' => (string) Str::uuid(),
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
            }

            $lib->refreshCounters();
            foreach ($lib->segments as $seg) {
                $seg->refreshCounter();
            }

            return $lib;
        });

        return response()->json([
            'data' => $this->presentLibrary($newLibrary->fresh(), $orgId),
        ], 201);
    }

    /**
     * PATCH /api/tprm/libraries/{id}
     * Update meta library (name, description, tags, is_active).
     */
    public function update(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

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

        return response()->json(['data' => $this->presentLibrary($library->fresh(), $orgId)]);
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
        $library = $this->findOwnedOrFail($id, $orgId);

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

        return response()->json(['data' => $segment], 201);
    }

    /**
     * PATCH /api/tprm/libraries/{id}/segments/{segmentId}
     */
    public function updateSegment(Request $request, string $id, string $segmentId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $segment = QuestionLibrarySegment::query()
            ->where('id', $segmentId)
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

        return response()->json(['data' => $segment->fresh()]);
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
        $library = $this->findOwnedOrFail($id, $orgId);

        $segment = QuestionLibrarySegment::query()
            ->where('id', $segmentId)
            ->where('library_id', $library->id)
            ->firstOrFail();

        $segment->delete();
        $library->refreshCounters();

        return response()->json(['message' => 'Segment dihapus.']);
    }

    /**
     * POST /api/tprm/libraries/{id}/segments/reorder
     * Body: { "order": ["segId1", "segId2", "segId3"] }
     * Bulk reorder via array order — lebih simple daripada per-segment update.
     */
    public function reorderSegments(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|string',
        ]);

        DB::transaction(function () use ($library, $data) {
            foreach ($data['order'] as $i => $segId) {
                QuestionLibrarySegment::query()
                    ->where('id', $segId)
                    ->where('library_id', $library->id)
                    ->update(['order_index' => $i]);
            }
        });

        return response()->json(['message' => 'Urutan segment diperbarui.']);
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
        $library = $this->findOwnedOrFail($id, $orgId);

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
            ->where('id', $data['library_segment_id'])
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

        return response()->json(['data' => $question], 201);
    }

    /**
     * PATCH /api/tprm/libraries/{id}/questions/{questionId}
     */
    public function updateQuestion(Request $request, string $id, string $questionId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $question = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $questionId)
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

        return response()->json(['data' => $question->fresh()]);
    }

    /**
     * DELETE /api/tprm/libraries/{id}/questions/{questionId}
     */
    public function destroyQuestion(Request $request, string $id, string $questionId)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $question = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $questionId)
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

        return response()->json(['message' => 'Pertanyaan dihapus.']);
    }

    /**
     * POST /api/tprm/libraries/{id}/questions/reorder
     * Body: { "order": ["qId1", "qId2", "qId3"] }
     */
    public function reorderQuestions(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $library = $this->findOwnedOrFail($id, $orgId);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|string',
        ]);

        DB::transaction(function () use ($library, $orgId, $data) {
            foreach ($data['order'] as $i => $qId) {
                VendorQuestionnaire::query()
                    ->withoutGlobalScope('org')
                    ->where('id', $qId)
                    ->where('library_id', $library->id)
                    ->where('org_id', $orgId)
                    ->update(['sort_order' => $i]);
            }
        });

        return response()->json(['message' => 'Urutan pertanyaan diperbarui.']);
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
        $library = $this->findOwnedOrFail($id, $orgId);

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
        ], 201);
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
            'segments_count' => $lib->segments_count,
            'questions_count' => $lib->questions_count,
            'tags' => $lib->tags ?? [],
            'cloned_from_library_id' => $lib->cloned_from_library_id,
            'created_at' => $lib->created_at?->toIso8601String(),
            'updated_at' => $lib->updated_at?->toIso8601String(),
        ];
    }
}
