<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CountryAdequacy;
use App\Models\CrossBorderTransfer;
use App\Services\AiService;
use App\Services\ApprovalWorkflowDispatcher;
use App\Services\AssessmentAutoTriggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CrossBorderController extends Controller
{
    /**
     * Allowed legal bases for cross-border transfer (UU PDP Pasal 56 + GDPR Ch. V).
     * Used by both store/update validation AND TIA recommender.
     */
    private const LEGAL_BASES = [
        'none', 'adequacy', 'sccs', 'bcr', 'consent',
        'contract_necessity', 'public_interest', 'vital_interest',
    ];

    private const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    private const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'expired'];

    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        // Respect ?per_page (clamp 1..200). TIA source picker minta 200 supaya
        // semua CBDT muncul; tanpa ini hardcoded 15 memotong daftar.
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 200));
        $transfers = CrossBorderTransfer::where('org_id', $orgId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($transfers);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->writeRules(false));

        $transfer = CrossBorderTransfer::create(array_merge($data, [
            'org_id' => $request->user()->org_id,
            'status' => $data['status'] ?? 'draft',
        ]));

        // Sprint X4 — every cross-border transfer needs a TIA per UU PDP Pasal 56.
        // Service wraps in try/catch so failures can't fail the create.
        $autoTiaId = null;
        try {
            $tia = app(AssessmentAutoTriggerService::class)
                ->fromCrossBorder($transfer, $request->user()->id);
            $autoTiaId = $tia?->id;
        } catch (\Throwable $e) {
            Log::warning('Auto-TIA on CrossBorder store failed (non-fatal): '.$e->getMessage());
        }

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'info', severity: 'medium', module: 'cross-border',
                type: 'cross_border.created', recipient: 'role:dpo,admin', orgId: $transfer->org_id,
                title: 'Transfer lintas negara baru: '.($transfer->destination_country ?? ''),
                body: ($transfer->transfer_purpose ?? 'Cross-border transfer').' — wajib TIA (Pasal 56 UU PDP).',
                actionUrl: '/cross-border', metadata: ['record_id' => $transfer->id],
            );
        } catch (\Throwable $e) { Log::warning('cross_border.created notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'Data transfer berhasil didaftarkan',
            'data' => $transfer,
            'auto_tia_id' => $autoTiaId,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);

        return response()->json(['data' => $transfer]);
    }

    public function update(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);
        $oldStatus = $transfer->status;
        $data = $request->validate($this->writeRules(true));
        $transfer->update($data);

        // Trigger Approval Workflow saat status → 'pending' (submit for approval).
        // Cross-border butuh sign-off compliance sebelum diakui sah.
        if (
            isset($data['status'])
            && $data['status'] === 'pending'
            && $oldStatus !== 'pending'
        ) {
            ApprovalWorkflowDispatcher::dispatch(
                $transfer->org_id, 'cross_border', $transfer->id
            );
            try {
                \App\Services\NotificationService::dispatch(
                    kind: 'warning', severity: 'medium', module: 'cross-border',
                    type: 'cross_border.submitted', recipient: 'role:dpo,admin', orgId: $transfer->org_id,
                    title: 'Transfer lintas negara menunggu approval: '.($transfer->destination_country ?? ''),
                    body: ($transfer->transfer_purpose ?? '').' — perlu sign-off compliance.',
                    actionUrl: '/cross-border', metadata: ['record_id' => $transfer->id],
                );
            } catch (\Throwable $e) { Log::warning('cross_border.submitted notif failed: '.$e->getMessage()); }
        }

        return response()->json(['message' => 'Data transfer berhasil diperbarui', 'data' => $transfer->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);
        $transfer->delete();

        return response()->json(['message' => 'Data transfer berhasil dihapus']);
    }

    public function trashed(Request $request)
    {
        $transfers = CrossBorderTransfer::onlyTrashed()->where('org_id', $request->user()->org_id)
            ->orderBy('deleted_at', 'desc')->get();

        return response()->json($transfers);
    }

    public function restore(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $transfer->restore();

        return response()->json(['message' => 'Data transfer berhasil dipulihkan']);
    }

    public function forceDelete(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $transfer->forceDelete();

        return response()->json(['message' => 'Data transfer dihapus permanen']);
    }

    /**
     * Conduct Transfer Impact Assessment (TIA).
     *
     * Two modes — both fully functional:
     *  - mode=manual: skor + safeguards + legal_basis dihitung dari jawaban form
     *    (rubric Pasal 56 UU PDP). Selalu jalan, no AI dependency.
     *  - mode=ai (default if credits available): jawaban dianalisa AI untuk skor
     *    + rekomendasi safeguards + recommended legal basis. Fallback ke manual
     *    rubric kalau AI gagal/error/credits habis — user TIDAK kehilangan TIA.
     *
     * Dipanggil berulang untuk re-assess.
     */
    public function assessTIA(Request $request, $id)
    {
        $user = $request->user();
        $transfer = CrossBorderTransfer::where('org_id', $user->org_id)->findOrFail($id);

        $validated = $request->validate([
            'tia_answers' => 'required|array',
            'mode' => 'nullable|in:manual,ai,auto',
            // Manual fields — used in manual mode OR as fallback when AI fails
            'manual_score' => 'nullable|integer|min:0|max:100',
            'manual_risk_level' => 'nullable|in:'.implode(',', self::RISK_LEVELS),
            'manual_safeguards' => 'nullable|array',
            'manual_safeguards.*' => 'string|max:300',
            'manual_legal_basis' => 'nullable|in:'.implode(',', self::LEGAL_BASES),
        ]);

        $answers = $validated['tia_answers'];
        $mode = $validated['mode'] ?? 'auto';
        $hasCredits = ($user->organization->ai_credits_remaining ?? 0) > 0;
        $useAi = $mode === 'ai' || ($mode === 'auto' && $hasCredits);

        $result = null;
        $aiUsed = false;
        $aiError = null;

        if ($useAi && $hasCredits) {
            try {
                $aiService = (new AiService($user->org_id))->setLocale($user->locale ?? 'id');
                $aiOut = $aiService->vendorTia(
                    ['tia_answers' => $answers, 'transfer_purpose' => $transfer->transfer_purpose],
                    $transfer->destination_country
                );

                if ($aiOut && isset($aiOut['tia_score'])) {
                    $score = (int) $aiOut['tia_score'];
                    $result = [
                        'score' => $score,
                        'risk_level' => $this->scoreToRiskLevel($score),
                        'safeguards' => $aiOut['safeguard_recommendations'] ?? [],
                        'legal_basis' => $aiOut['legal_basis_recommended'] ?? null,
                        'source' => 'ai',
                    ];
                    $user->organization->decrement('ai_credits_remaining', 1);
                    $aiUsed = true;
                }
            } catch (\Throwable $e) {
                $aiError = $e->getMessage();
                Log::warning('AI TIA failed, falling back to manual rubric: '.$aiError);
            }
        }

        // Manual fallback OR explicit manual mode
        if ($result === null) {
            $result = $this->manualTia($validated, $transfer, $answers);
        }

        // Preserve user-supplied safeguards if provided (don't let AI overwrite explicit input)
        if (! empty($validated['manual_safeguards'])) {
            $result['safeguards'] = $validated['manual_safeguards'];
        }
        if (! empty($validated['manual_legal_basis'])) {
            $result['legal_basis'] = $validated['manual_legal_basis'];
        }

        $transfer->update([
            'tia_answers' => $answers,
            'risk_score' => $result['score'],
            'risk_level' => $result['risk_level'],
            'tia_summary' => json_encode([
                'source' => $result['source'],
                'legal_basis' => $result['legal_basis'],
                'safeguards' => $result['safeguards'],
                'assessed_at' => now()->toIso8601String(),
                'ai_error' => $aiError,
            ]),
            'safeguards' => ! empty($result['safeguards']) ? $result['safeguards'] : ($transfer->safeguards ?? []),
            'legal_basis' => $result['legal_basis'] ?: $transfer->legal_basis,
            'status' => in_array($result['risk_level'], ['high', 'critical'], true) ? 'pending' : 'approved',
            'approved_at' => in_array($result['risk_level'], ['low', 'medium'], true) ? now() : null,
            'review_due_at' => now()->addYear(),
        ]);

        return response()->json([
            'message' => $aiUsed
                ? 'Transfer Impact Assessment selesai (AI-assisted).'
                : 'Transfer Impact Assessment selesai (manual rubric).',
            'data' => $transfer->fresh(),
            'ai_used' => $aiUsed,
            'ai_error' => $aiError,
        ]);
    }

    /**
     * Deterministic manual TIA — jawaban TIA di-skor pakai rubric tetap supaya
     * hasilnya konsisten antar pengguna & antar org.
     *
     * Skor turun jika:
     *  - destination country bukan adequacy-listed (UU PDP Pasal 56 ayat 1)
     *  - data sensitif / spesifik tanpa SCCs/BCR
     *  - tidak ada DPA / encryption-at-rest
     *
     * Skor naik jika:
     *  - SCCs/BCR/Adequacy decision in place
     *  - Encryption + access logging + DPIA done
     */
    private function manualTia(array $validated, CrossBorderTransfer $transfer, array $answers): array
    {
        // Honor explicit manual override score
        if (isset($validated['manual_score'])) {
            $score = $validated['manual_score'];
            $level = $validated['manual_risk_level'] ?? $this->scoreToRiskLevel($score);

            return [
                'score' => $score,
                'risk_level' => $level,
                'safeguards' => $validated['manual_safeguards'] ?? ($transfer->safeguards ?? []),
                'legal_basis' => $validated['manual_legal_basis'] ?? $transfer->legal_basis,
                'source' => 'manual',
            ];
        }

        // Rubric-based: start at 70 (medium-good), adjust ±
        $score = 70;
        $safeguards = $validated['manual_safeguards'] ?? [];
        $legalBasis = $validated['manual_legal_basis'] ?? $transfer->legal_basis ?? 'none';

        // Legal basis weight
        $score += match ($legalBasis) {
            'adequacy', 'bcr' => 15,
            'sccs', 'contract_necessity' => 10,
            'consent' => 0,
            'public_interest', 'vital_interest' => -5,
            default => -25, // 'none' or invalid
        };

        // Yes/no rubric from common TIA questions
        foreach ($answers as $key => $val) {
            $bool = is_bool($val) ? $val : in_array(strtolower((string) $val), ['yes', 'ya', 'true', '1'], true);
            if (str_contains($key, 'sensitive') && $bool) {
                $score -= 15;
            }
            if (str_contains($key, 'encryption') && $bool) {
                $score += 8;
            }
            if (str_contains($key, 'dpa') && $bool) {
                $score += 7;
            }
            if (str_contains($key, 'audit') && $bool) {
                $score += 5;
            }
            if (str_contains($key, 'breach_history') && $bool) {
                $score -= 12;
            }
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'risk_level' => $this->scoreToRiskLevel($score),
            'safeguards' => $safeguards,
            'legal_basis' => $legalBasis !== 'none' ? $legalBasis : null,
            'source' => 'manual',
        ];
    }

    private function scoreToRiskLevel(int $score): string
    {
        if ($score >= 85) {
            return 'low';
        }
        if ($score >= 65) {
            return 'medium';
        }
        if ($score >= 40) {
            return 'high';
        }

        return 'critical';
    }

    /**
     * Validation rules shared by store + update.
     * `$forUpdate=true` → all fields become `sometimes`.
     */
    private function writeRules(bool $forUpdate): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';
        $opt = $forUpdate ? 'sometimes|nullable' : 'nullable';

        return [
            'destination_country' => "{$req}|string|max:100",
            'destination_entity' => "{$req}|string|max:255",
            'transfer_purpose' => "{$req}|string|max:2000",
            'legal_basis' => "{$req}|in:".implode(',', self::LEGAL_BASES),
            'data_categories' => "{$opt}|array",
            'data_categories.*' => 'string|max:200',
            'safeguards' => "{$opt}|array",
            'safeguards.*' => 'string|max:300',
            'status' => "{$opt}|in:".implode(',', self::STATUSES),
            'risk_score' => "{$opt}|integer|min:0|max:100",
            'risk_level' => "{$opt}|in:".implode(',', self::RISK_LEVELS),
            'review_due_at' => "{$opt}|date",
            'approved_at' => "{$opt}|date",
            'ropa_id' => "{$opt}|uuid",

            // Phase 1 — transfer profile (drives TIA risk pre-fill)
            'transfer_volume_band' => "{$opt}|in:".implode(',', CrossBorderTransfer::VOLUME_BANDS),
            'transfer_frequency' => "{$opt}|in:".implode(',', CrossBorderTransfer::FREQUENCIES),
            'data_sensitivity' => "{$opt}|in:".implode(',', CrossBorderTransfer::SENSITIVITIES),
            'transfer_mechanism' => "{$opt}|in:".implode(',', CrossBorderTransfer::MECHANISMS),
            'encryption_in_transit' => "{$opt}|boolean",
            'encryption_at_rest' => "{$opt}|boolean",
            'data_minimization_applied' => "{$opt}|boolean",
            'retention_period_days' => "{$opt}|integer|min:1|max:36500",
            'recipient_dpo_name' => "{$opt}|string|max:255",
            'recipient_dpo_email' => "{$opt}|email|max:255",
            'linked_ropa_id' => "{$opt}|uuid|exists:ropas,id",
        ];
    }

    /**
     * Country adequacy lookup. Used by the FE when user types/selects
     * a destination country — returns the tier classification + default
     * risk score pre-fills + Pasal 56 safeguard recommendation hint.
     *
     * GET /cross-border/countries           → list all (for autocomplete)
     * GET /cross-border/countries/{code}    → resolve one
     */
    public function listCountries(Request $request)
    {
        $q = $request->get('q');
        $tier = $request->get('tier');

        $query = CountryAdequacy::query()->where('is_active', true);
        if ($q) {
            $like = '%'.strtolower($q).'%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(country_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(country_code) LIKE ?', [$like]);
            });
        }
        if ($tier) {
            $query->where('tier', $tier);
        }

        return response()->json([
            'data' => $query->orderBy('tier')->orderBy('country_name')->get(),
            'tier_labels' => CountryAdequacy::TIER_LABELS,
        ]);
    }

    public function resolveCountry(Request $request, string $codeOrName)
    {
        $rec = CountryAdequacy::resolve($codeOrName);
        if (! $rec) {
            return response()->json([
                'message' => 'Country not in adequacy lookup. Treat as Tier "none" and require safeguards.',
                'data' => null,
                'tier_label' => CountryAdequacy::TIER_LABELS[CountryAdequacy::TIER_NONE],
            ]);
        }

        return response()->json([
            'data' => $rec,
            'tier_label' => CountryAdequacy::TIER_LABELS[$rec->tier] ?? $rec->tier,
        ]);
    }
}
