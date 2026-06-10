<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\VendorQuestionnaire;
use Illuminate\Http\Request;

/**
 * Sprint G.4 — Customisasi pertanyaan TPRM per-tenant.
 *
 * Operasi yang didukung:
 *   - GET    /api/third-party/questions           — list effective (default + override + custom)
 *   - POST   /api/third-party/questions           — tambah pertanyaan custom tenant
 *   - PUT    /api/third-party/questions/{id}      — edit (fork system row, atau edit in-place)
 *   - DELETE /api/third-party/questions/{id}      — tombstone system row, atau soft-delete custom
 *
 * Model resolusi (lihat VendorQuestionnaire::effectiveForOrg):
 *   - System default      → org_id NULL
 *   - Tenant override     → org_id = tenant, parent_id = system_id
 *   - Tenant custom       → org_id = tenant, parent_id = NULL
 *
 * Catatan: VendorQuestionnaire pakai trait LandlordPinned, jadi tidak terikat
 * tenant connection switching. Query manual filter org_id supaya tenant tidak
 * bisa lihat / ubah pertanyaan tenant lain.
 */
class ThirdPartyQuestionController extends Controller
{
    /**
     * GET /api/third-party/questions
     * Return effective questionnaire untuk tenant aktif: default + override + custom.
     * Filter optional by version (default v2_2026) dan category.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $version = $request->query('version', 'v2_2026');
        $category = $request->query('category');

        // effectiveForOrg sudah merge default + override + custom + filter is_active
        $questions = VendorQuestionnaire::effectiveForOrg($orgId)
            ->filter(fn ($q) => $q->version === $version)
            ->when($category, fn ($coll) => $coll->filter(fn ($q) => $q->category === $category))
            ->map(fn ($q) => $this->presentQuestion($q, $orgId))
            ->values();

        // Tambahan: surface tombstone (override yang aktif=false) supaya UI bisa
        // tampilkan opsi "Aktifkan kembali" — effectiveForOrg menyembunyikan ini.
        $tombstones = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereNotNull('parent_id')
            ->where('is_active', false)
            ->get()
            ->map(fn ($q) => $this->presentQuestion($q, $orgId));

        return response()->json([
            'data' => $questions,
            'tombstones' => $tombstones->values(),
            'version' => $version,
            'sections' => VendorQuestionnaire::SECTION_LABELS,
        ]);
    }

    /**
     * POST /api/third-party/questions
     * Tambah pertanyaan custom untuk tenant — parent_id NULL, org_id = tenant.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string|max:64',
            'version' => 'nullable|string|max:16',
            'section' => 'required|string|max:64',
            'question_code' => 'nullable|string|max:16',
            'question_text' => 'required|string|max:2000',
            'description' => 'nullable|string|max:2000',
            'regulation_ref' => 'nullable|string|max:255',
            'recommendation_if_no' => 'nullable|string|max:2000',
            'requires_evidence_upload' => 'nullable|boolean',
            'answer_type' => 'nullable|string|in:yes_no,multi_choice,scale_1_5',
            'answer_options' => 'nullable|array',
            'weight' => 'nullable|integer|min:1|max:10',
            'direction' => 'nullable|integer|in:-1,1',
            'sort_order' => 'nullable|integer',
        ]);

        $orgId = $request->user()->org_id;
        $version = $data['version'] ?? 'v2_2026';

        // Auto-generate question_code kalau tidak disuplai (CUSTOM-XXX)
        if (empty($data['question_code'])) {
            $existing = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->whereNull('parent_id')
                ->where('question_code', 'like', 'CUSTOM-%')
                ->count();
            $data['question_code'] = sprintf('CUSTOM-%03d', $existing + 1);
        }

        // sort_order default = max + 10 supaya custom muncul di belakang default
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('version', $version)
                ->max('sort_order') + 10;
        }

        $question = VendorQuestionnaire::create(array_merge($data, [
            'org_id' => $orgId,
            'parent_id' => null,
            'version' => $version,
            'answer_type' => $data['answer_type'] ?? 'yes_no',
            'weight' => $data['weight'] ?? 5,
            'direction' => $data['direction'] ?? 1,
            'requires_evidence_upload' => $data['requires_evidence_upload'] ?? false,
            'is_active' => true,
        ]));

        return response()->json([
            'message' => 'Pertanyaan custom berhasil ditambahkan.',
            'data' => $this->presentQuestion($question->fresh(), $orgId),
        ], 201);
    }

    /**
     * PUT /api/third-party/questions/{id}
     * Update perilaku:
     *   - Kalau row system (org_id NULL): fork — buat override baru dengan
     *     parent_id = id_system, fields diisi dari payload.
     *   - Kalau row sudah tenant-owned (org_id = tenant): edit in-place.
     */
    public function update(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;

        $original = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $id)
            ->where(function ($q) use ($orgId) {
                // System row (org_id NULL) ATAU tenant-owned milik org ini
                $q->whereNull('org_id')->orWhere('org_id', $orgId);
            })
            ->firstOrFail();

        $data = $request->validate([
            'section' => 'sometimes|string|max:64',
            'question_text' => 'sometimes|string|max:2000',
            'description' => 'nullable|string|max:2000',
            'regulation_ref' => 'nullable|string|max:255',
            'recommendation_if_no' => 'nullable|string|max:2000',
            'requires_evidence_upload' => 'nullable|boolean',
            'answer_type' => 'sometimes|string|in:yes_no,multi_choice,scale_1_5',
            'answer_options' => 'nullable|array',
            'weight' => 'nullable|integer|min:1|max:10',
            'direction' => 'nullable|integer|in:-1,1',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        // Copy-on-write kalau row adalah system (org_id NULL)
        if ($original->org_id === null) {
            // Cek apakah override sudah ada — kalau ada, edit override existing
            $override = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('parent_id', $original->id)
                ->first();

            if (! $override) {
                $override = VendorQuestionnaire::create([
                    'org_id' => $orgId,
                    'parent_id' => $original->id,
                    'category' => $original->category,
                    'version' => $original->version,
                    'question_code' => $original->question_code,
                    'section' => $original->section,
                    'question_text' => $original->question_text,
                    'description' => $original->description,
                    'regulation_ref' => $original->regulation_ref,
                    'recommendation_if_no' => $original->recommendation_if_no,
                    'requires_evidence_upload' => $original->requires_evidence_upload,
                    'answer_type' => $original->answer_type,
                    'answer_options' => $original->answer_options,
                    'weight' => $original->weight,
                    'direction' => $original->direction,
                    'is_active' => $original->is_active,
                    'sort_order' => $original->sort_order,
                ]);
            }

            $override->fill($data)->save();

            return response()->json([
                'message' => 'Pertanyaan default berhasil diubah (fork ke override tenant).',
                'data' => $this->presentQuestion($override->fresh(), $orgId),
            ]);
        }

        // Tenant-owned (custom atau override existing) — edit in-place
        $original->fill($data)->save();

        return response()->json([
            'message' => 'Pertanyaan berhasil diubah.',
            'data' => $this->presentQuestion($original->fresh(), $orgId),
        ]);
    }

    /**
     * DELETE /api/third-party/questions/{id}
     * Perilaku:
     *   - System row → bikin override tombstone (is_active=false) supaya
     *     pertanyaan default hilang dari effective set untuk tenant ini.
     *   - Tenant-owned (custom atau override) → soft-delete (delete physical row).
     *     Custom: row hilang sepenuhnya. Override: default kembali muncul.
     */
    public function destroy(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;

        $original = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('id', $id)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('org_id')->orWhere('org_id', $orgId);
            })
            ->firstOrFail();

        if ($original->org_id === null) {
            // Tombstone — override system row dengan is_active=false
            $override = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('parent_id', $original->id)
                ->first();

            if ($override) {
                $override->is_active = false;
                $override->save();
            } else {
                VendorQuestionnaire::create([
                    'org_id' => $orgId,
                    'parent_id' => $original->id,
                    'category' => $original->category,
                    'version' => $original->version,
                    'question_code' => $original->question_code,
                    'section' => $original->section,
                    'question_text' => $original->question_text,
                    'description' => $original->description,
                    'regulation_ref' => $original->regulation_ref,
                    'recommendation_if_no' => $original->recommendation_if_no,
                    'requires_evidence_upload' => $original->requires_evidence_upload,
                    'answer_type' => $original->answer_type,
                    'answer_options' => $original->answer_options,
                    'weight' => $original->weight,
                    'direction' => $original->direction,
                    'is_active' => false,
                    'sort_order' => $original->sort_order,
                ]);
            }

            return response()->json([
                'message' => 'Pertanyaan default berhasil dinonaktifkan untuk organisasi Anda.',
            ]);
        }

        // Tenant-owned — hard delete (tabel tidak punya soft delete column)
        $original->delete();

        return response()->json([
            'message' => 'Pertanyaan berhasil dihapus.',
        ]);
    }

    /**
     * POST /api/third-party/questions/factory-reset
     * Reset TOTAL set pertanyaan "Kelola Pertanyaan" org ke default pabrikan
     * (paritas dengan GAP/Maturity/TIA/LIA):
     *   - Semua override default org (org_id=org, parent_id NOT NULL) dihapus
     *     permanen → edit di-revert + default yang dinonaktifkan (tombstone)
     *     otomatis aktif lagi.
     *   - Semua pertanyaan custom org (org_id=org, parent_id NULL) dihapus —
     *     tabel ini tidak punya soft delete (semantik sama dengan destroy()).
     *
     * PENTING — yang TIDAK disentuh:
     *   - Baris system default (org_id NULL) milik platform.
     *   - Pertanyaan milik library tenant (library_id NOT NULL) — itu dikelola
     *     lewat Bank Pertanyaan TPRM (/tprm/libraries), bukan Kelola Pertanyaan.
     *     Asesmen yang terikat library (library_id terisi) tidak terpengaruh.
     *
     * Risiko yang disengaja & dikomunikasikan di confirm FE: asesmen berjalan
     * TANPA library (library_id NULL — jalur legacy effectiveForOrg) akan
     * melihat set pertanyaan berubah; jawaban tersimpan pada pertanyaan
     * custom/override yang dihapus menjadi orphan.
     */
    public function factoryResetQuestions(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Org context required.'], 403);
        }

        $overridesRemoved = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereNotNull('parent_id')
            ->delete();

        $customsRemoved = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereNull('parent_id')
            ->whereNull('library_id') // jangan sentuh pertanyaan milik library tenant
            ->delete();

        AuditLog::log('vendor_risk', $orgId, 'questions_factory_reset', [
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);

        return response()->json([
            'message' => 'Semua pertanyaan dikembalikan ke default pabrikan.',
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);
    }

    /**
     * Format question untuk response API. Tambah field 'source' (default|custom|
     * override) supaya frontend bisa render badge yang sesuai.
     */
    private function presentQuestion(VendorQuestionnaire $q, ?string $orgId): array
    {
        $source = 'default';
        if ($q->org_id !== null) {
            $source = $q->parent_id ? 'override' : 'custom';
        }

        return [
            'id' => $q->id,
            'org_id' => $q->org_id,
            'parent_id' => $q->parent_id,
            'source' => $source, // default | override | custom
            'is_system' => $q->org_id === null,
            'category' => $q->category,
            'version' => $q->version,
            'question_code' => $q->question_code,
            'section' => $q->section,
            'section_label' => VendorQuestionnaire::SECTION_LABELS[$q->section] ?? $q->section,
            'question_text' => $q->question_text,
            'description' => $q->description,
            'regulation_ref' => $q->regulation_ref,
            'recommendation_if_no' => $q->recommendation_if_no,
            'requires_evidence_upload' => (bool) $q->requires_evidence_upload,
            'answer_type' => $q->answer_type,
            'answer_options' => $q->answer_options,
            'weight' => $q->weight,
            'direction' => $q->direction,
            'is_active' => (bool) $q->is_active,
            'sort_order' => $q->sort_order,
        ];
    }
}
