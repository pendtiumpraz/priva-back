<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomTriageQuestion;
use App\Models\TriageQuestionOverride;
use App\Models\VendorPreAssessment;
use Illuminate\Http\Request;

/**
 * Kelola Pertanyaan Triage (Pre-Assessment / Penyaringan Lingkup PDP).
 *
 * Mirror 1:1 dari LiaController question-management:
 *   - effective set (default + override per-org + custom),
 *   - upsert override default (diff-aware, NULL=default),
 *   - reset 1 pertanyaan default,
 *   - factory-reset (force-delete overrides + soft-delete customs),
 *   - CRUD custom questions (CUST-N auto code).
 *
 * Permission slug: vendor_risk (TPRM tidak punya slug terpisah). Reads butuh
 * vendor_risk,read; mutasi butuh vendor_risk,write (di routes).
 */
class TriageQuestionController extends Controller
{
    /**
     * GET /third-party/triage-questions (?include_inactive=1)
     * Set pertanyaan triage EFEKTIF untuk org pemanggil.
     */
    public function index(Request $request)
    {
        $questions = VendorPreAssessment::effectiveQuestions(
            $request->user()?->org_id,
            $request->boolean('include_inactive'),
        );

        return response()->json(['data' => array_values($questions)]);
    }

    /**
     * PUT /third-party/triage-questions/{code}
     * Upsert override default org. Field yang sama dengan default disimpan
     * NULL (= pakai default) supaya flag is_overridden akurat + reset bersih.
     * is_core BISA di-toggle untuk pertanyaan default (menentukan decisive).
     */
    public function updateDefaultQuestion(Request $request, string $code)
    {
        $request->validate([
            'text' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'is_core' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $default = collect(VendorPreAssessment::DEFAULT_QUESTIONS)->firstWhere('question_code', $code);
        if (! $default) {
            return response()->json(['message' => 'Pertanyaan triage default tidak ditemukan.'], 404);
        }

        $orgId = $request->user()->org_id;

        $values = [];
        foreach (['text', 'description'] as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                $val = is_string($val) ? trim($val) : $val;
                $values[$field] = ($val === null || $val === '' || $val === ($default[$field] ?? null)) ? null : $val;
            }
        }
        if ($request->has('is_core')) {
            // Sama dengan default → NULL (pakai default); beda → simpan.
            $values['is_core'] = ($request->boolean('is_core') === (bool) ($default['is_core'] ?? false))
                ? null
                : $request->boolean('is_core');
        }
        if ($request->has('is_active')) {
            $values['is_active'] = $request->boolean('is_active');
        }

        $override = TriageQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $code)
            ->first();

        if ($override) {
            if ($override->trashed()) {
                $override->restore();
            }
            $override->fill($values)->save();
        } else {
            $override = TriageQuestionOverride::create(array_merge([
                'org_id' => $orgId,
                'question_code' => $code,
                'is_active' => true,
            ], $values));
        }

        // No-op override (semua field null + masih aktif) → buang row.
        if (! $override->hasEffect()) {
            $override->forceDelete();
        }

        $effective = collect(VendorPreAssessment::effectiveQuestions($orgId, true))
            ->firstWhere('question_code', $code);

        return response()->json([
            'message' => 'Pertanyaan triage default diperbarui.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /third-party/triage-questions/{code}/reset
     * Hapus override org → pertanyaan kembali ke nilai katalog default.
     */
    public function resetDefaultQuestion(Request $request, string $code)
    {
        $orgId = $request->user()->org_id;

        TriageQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $code)
            ->forceDelete();

        $effective = collect(VendorPreAssessment::effectiveQuestions($orgId, true))
            ->firstWhere('question_code', $code);

        return response()->json([
            'message' => 'Pertanyaan dikembalikan ke default.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /third-party/triage-questions/factory-reset
     * Reset TOTAL ke default pabrikan: force-delete semua override +
     * soft-delete semua custom question org.
     */
    public function factoryResetQuestions(Request $request)
    {
        $orgId = $request->user()->org_id;

        $overridesRemoved = TriageQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->forceDelete();

        $customsRemoved = CustomTriageQuestion::forOrg($orgId)->delete();

        AuditLog::log('tprm.triage_questions', $orgId, 'questions_factory_reset', [
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);

        return response()->json([
            'message' => 'Semua pertanyaan triage dikembalikan ke default pabrikan.',
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);
    }

    // =============================================
    // Custom Questions CRUD
    // =============================================

    public function customIndex(Request $request)
    {
        $questions = CustomTriageQuestion::forOrg($request->user()->org_id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    public function storeCustom(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'is_core' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $orgId = $request->user()->org_id;

        $lastNum = CustomTriageQuestion::withTrashed()
            ->where('org_id', $orgId)
            ->pluck('question_code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->max() ?? 0;

        $question = CustomTriageQuestion::create([
            'org_id' => $orgId,
            'question_code' => 'CUST-'.($lastNum + 1),
            'text' => $data['text'],
            'description' => $data['description'] ?? null,
            'is_core' => $request->boolean('is_core', true),
            'sort_order' => $data['sort_order']
                ?? ((int) CustomTriageQuestion::forOrg($orgId)->max('sort_order') + 1),
        ]);

        return response()->json(['message' => 'Pertanyaan triage custom ditambahkan.', 'data' => $question], 201);
    }

    public function updateCustom(Request $request, string $id)
    {
        $question = CustomTriageQuestion::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'text' => 'sometimes|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'is_core' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $question->update($request->only([
            'text', 'description', 'is_core', 'sort_order', 'is_active',
        ]));

        return response()->json(['message' => 'Pertanyaan triage custom diperbarui.', 'data' => $question->fresh()]);
    }

    public function destroyCustom(Request $request, string $id)
    {
        $question = CustomTriageQuestion::forOrg($request->user()->org_id)->findOrFail($id);
        $question->delete();

        return response()->json(['message' => 'Pertanyaan triage custom dihapus.']);
    }
}
