<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorPreAssessment;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * TPRM Pre-Assessment ("Penyaringan Lingkup PDP") — vendor triage.
 *
 * Gate BEFORE the full vendor assessment. Decides whether a third party is IN
 * SCOPE (touches personal data → full assessment needed) or OUT OF SCOPE
 * (e.g. furniture/AC vendor → recorded only). Flow:
 *
 *   1. GET  pre-assessment            → latest row + effective triage questions
 *                                       (creates empty draft if none).
 *   2. POST pre-assessment            → save/submit internal answers; on
 *                                       finalize computes suggested_scope. Does
 *                                       NOT touch vendor scope yet.
 *   3. POST .../decide                → reviewer confirms/overrides suggestion.
 *                                       in_scope → vendor scope set immediately;
 *                                       out_of_scope → out_of_scope_pending
 *                                       (awaits DPO) + justification required.
 *   4. POST .../approve-out-of-scope  → DPO approve/reject.
 *   5. POST .../public-link           → issue public token for the third party.
 *
 * Audit-logged throughout; OUT OF SCOPE always carries a justification trail.
 */
class VendorPreAssessmentController extends Controller
{
    public const DEFAULT_TOKEN_EXPIRY_DAYS = 30;

    /**
     * GET /vendor-risk/{vendorId}/pre-assessment
     * Latest pre-assessment for vendor + effective triage questions. Creates an
     * empty draft when none exists so the FE always has a row to bind to.
     */
    public function show(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        // Division-scoped: a user from another division can't open a vendor's
        // pre-assessment by guessing its id — visibleTo narrows the query so
        // findOrFail returns 404 (consistent with RoPA single-record gating).
        $vendor = Vendor::where('org_id', $orgId)
            ->visibleTo($request->user())
            ->findOrFail($vendorId);

        $pre = VendorPreAssessment::forOrg($orgId)
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $pre) {
            $pre = VendorPreAssessment::create([
                'org_id' => $orgId,
                'vendor_id' => $vendor->id,
                'answers' => [],
                'status' => VendorPreAssessment::STATUS_DRAFT,
            ]);
        }

        return response()->json([
            'data' => [
                'pre_assessment' => $pre,
                'vendor' => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'pdp_scope_status' => $vendor->pdp_scope_status,
                    'scope_overridden' => (bool) $vendor->scope_overridden,
                    'scope_justification' => $vendor->scope_justification,
                ],
                'questions' => array_values(VendorPreAssessment::effectiveQuestions($orgId)),
            ],
        ]);
    }

    /**
     * POST /vendor-risk/{vendorId}/pre-assessment
     * Save (and optionally finalize) internal triage answers.
     *   body: { answers: { code => 'ya'|'tidak'|null }, finalize: bool }
     * On finalize: status → submitted + suggested_scope computed. Vendor scope
     * is NOT changed here — that happens at /decide.
     */
    public function save(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $request->validate([
            'answers' => 'required|array',
            'answers.*' => ['nullable', Rule::in(['ya', 'tidak'])],
            'finalize' => 'nullable|boolean',
        ]);

        $pre = VendorPreAssessment::forOrg($orgId)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [VendorPreAssessment::STATUS_DRAFT, VendorPreAssessment::STATUS_SUBMITTED])
            ->orderByDesc('created_at')
            ->first();

        if (! $pre) {
            $pre = new VendorPreAssessment([
                'org_id' => $orgId,
                'vendor_id' => $vendor->id,
                'status' => VendorPreAssessment::STATUS_DRAFT,
            ]);
        }

        if ($pre->status === VendorPreAssessment::STATUS_DECIDED) {
            return response()->json([
                'message' => 'Pre-assessment sudah diputuskan. Mulai penyaringan ulang untuk mengubah jawaban.',
            ], 409);
        }

        $existing = is_array($pre->answers) ? $pre->answers : [];
        $pre->answers = array_merge($existing, $request->input('answers'));
        $pre->filled_by = VendorPreAssessment::FILLED_INTERNAL;

        if ($request->boolean('finalize')) {
            $pre->suggested_scope = VendorPreAssessment::suggestScope($pre->answers, $orgId);
            $pre->status = VendorPreAssessment::STATUS_SUBMITTED;
        }
        $pre->save();

        AuditLog::log('tprm.pre_assessment', $pre->id, $request->boolean('finalize') ? 'submitted' : 'saved', [
            'vendor_id' => $vendor->id,
            'suggested_scope' => $pre->suggested_scope,
            'filled_by' => $pre->filled_by,
        ], 'pre_assessment');

        return response()->json([
            'message' => $request->boolean('finalize')
                ? 'Penyaringan dikirim. Lingkup yang disarankan: '.$this->scopeLabel($pre->suggested_scope).'.'
                : 'Jawaban penyaringan tersimpan.',
            'data' => $pre->fresh(),
        ]);
    }

    /**
     * POST /vendor-risk/{vendorId}/pre-assessment/decide
     * Reviewer confirms or overrides the suggested scope.
     *   body: { final_scope: in_scope|out_of_scope, justification, overridden:bool }
     *
     *   in_scope      → vendor.pdp_scope_status = in_scope, scope_decided_*.
     *   out_of_scope  → vendor.pdp_scope_status = out_of_scope_PENDING (needs
     *                   DPO approval) + justification required.
     */
    public function decide(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $data = $request->validate([
            'final_scope' => ['required', Rule::in([VendorPreAssessment::SCOPE_IN, VendorPreAssessment::SCOPE_OUT])],
            'justification' => 'nullable|string|max:5000',
            'overridden' => 'nullable|boolean',
        ]);

        $pre = VendorPreAssessment::forOrg($orgId)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [VendorPreAssessment::STATUS_SUBMITTED, VendorPreAssessment::STATUS_DRAFT])
            ->orderByDesc('created_at')
            ->first();

        if (! $pre) {
            return response()->json([
                'message' => 'Belum ada penyaringan yang bisa diputuskan. Isi & kirim penyaringan terlebih dahulu.',
            ], 422);
        }

        $isOut = $data['final_scope'] === VendorPreAssessment::SCOPE_OUT;
        if ($isOut && empty(trim((string) ($data['justification'] ?? '')))) {
            return response()->json([
                'message' => 'Justifikasi wajib diisi untuk keputusan Di Luar Lingkup PDP.',
            ], 422);
        }

        // Compute suggestion if it was decided straight from a draft.
        if (empty($pre->suggested_scope)) {
            $pre->suggested_scope = VendorPreAssessment::suggestScope(
                is_array($pre->answers) ? $pre->answers : [], $orgId
            );
        }
        $overridden = $request->boolean('overridden')
            || ($pre->suggested_scope && $pre->suggested_scope !== $data['final_scope']);

        DB::transaction(function () use ($pre, $vendor, $request, $data, $isOut, $overridden) {
            $pre->forceFill([
                'final_scope' => $data['final_scope'],
                'justification' => $data['justification'] ?? null,
                'overridden' => $overridden,
                'status' => VendorPreAssessment::STATUS_DECIDED,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();

            if ($isOut) {
                // Out of scope → menunggu approval DPO. Belum final.
                $vendor->forceFill([
                    'pdp_scope_status' => Vendor::SCOPE_OUT_PENDING,
                    'scope_overridden' => $overridden,
                    'scope_justification' => $data['justification'] ?? null,
                    'scope_decided_by' => $request->user()->id,
                    'scope_decided_at' => now(),
                    'scope_approved_by' => null,
                    'scope_approved_at' => null,
                ])->save();
            } else {
                // In scope → langsung berlaku, tidak butuh approval.
                $vendor->forceFill([
                    'pdp_scope_status' => Vendor::SCOPE_IN,
                    'scope_overridden' => $overridden,
                    'scope_justification' => $data['justification'] ?? null,
                    'scope_decided_by' => $request->user()->id,
                    'scope_decided_at' => now(),
                    'scope_approved_by' => null,
                    'scope_approved_at' => null,
                ])->save();
            }
        });

        AuditLog::log('tprm.pre_assessment', $pre->id, 'scope_decided', [
            'vendor_id' => $vendor->id,
            'suggested_scope' => $pre->suggested_scope,
            'final_scope' => $data['final_scope'],
            'overridden' => $overridden,
            'justification' => $data['justification'] ?? null,
        ], 'pre_assessment');

        if ($isOut) {
            // Beritahu DPO/admin bahwa ada keputusan out-of-scope menunggu persetujuan.
            try {
                NotificationService::dispatch(
                    kind: 'warning', severity: 'medium', module: 'vendor-risk',
                    type: 'tprm.out_of_scope_pending', recipient: 'role:dpo,admin', orgId: $orgId,
                    title: "Persetujuan lingkup PDP: {$vendor->name}",
                    body: 'Pihak ketiga diputuskan Di Luar Lingkup PDP — menunggu persetujuan DPO.',
                    actionUrl: '/vendor-risk', metadata: ['record_id' => $vendor->id],
                );
            } catch (\Throwable $e) {
                Log::warning('tprm.out_of_scope_pending notif failed: '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => $isOut
                ? 'Keputusan Di Luar Lingkup PDP tercatat — menunggu persetujuan DPO.'
                : 'Pihak ketiga ditetapkan Dalam Lingkup PDP. Lanjutkan ke asesmen penuh.',
            'data' => [
                'pre_assessment' => $pre->fresh(),
                'vendor_pdp_scope_status' => $vendor->fresh()->pdp_scope_status,
            ],
        ]);
    }

    /**
     * POST /vendor-risk/{vendorId}/pre-assessment/approve-out-of-scope
     * DPO approve/reject the out-of-scope decision.
     *   body: { action: approve|reject, notes }
     *   approve → vendor 'out_of_scope', scope_approved_*; notify decider.
     *   reject  → back to 'in_scope' (treat as in scope; full assessment needed);
     *             reason recorded; notify decider.
     */
    public function approveOutOfScope(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($vendor->pdp_scope_status !== Vendor::SCOPE_OUT_PENDING) {
            return response()->json([
                'message' => 'Tidak ada keputusan Di Luar Lingkup yang menunggu persetujuan untuk pihak ketiga ini.',
            ], 409);
        }

        $pre = VendorPreAssessment::forOrg($orgId)
            ->where('vendor_id', $vendor->id)
            ->where('status', VendorPreAssessment::STATUS_DECIDED)
            ->orderByDesc('decided_at')
            ->first();

        $approve = $data['action'] === 'approve';

        DB::transaction(function () use ($vendor, $pre, $request, $data, $approve) {
            if ($approve) {
                $vendor->forceFill([
                    'pdp_scope_status' => Vendor::SCOPE_OUT,
                    'scope_approved_by' => $request->user()->id,
                    'scope_approved_at' => now(),
                ])->save();

                $pre?->forceFill([
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ])->save();
            } else {
                // Reject → pihak ketiga diperlakukan Dalam Lingkup (butuh asesmen penuh).
                $vendor->forceFill([
                    'pdp_scope_status' => Vendor::SCOPE_IN,
                    'scope_approved_by' => null,
                    'scope_approved_at' => null,
                ])->save();

                $pre?->forceFill([
                    'approved_by' => null,
                    'approved_at' => null,
                    'rejected_at' => now(),
                    'rejection_reason' => $data['notes'] ?? 'Ditolak oleh DPO.',
                ])->save();
            }
        });

        AuditLog::log('tprm.pre_assessment', $pre?->id ?? $vendor->id, 'out_of_scope_'.$data['action'], [
            'vendor_id' => $vendor->id,
            'approver_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ], 'pre_assessment');

        // Notify the decider of the DPO outcome.
        $deciderId = $pre?->decided_by;
        if ($deciderId) {
            try {
                NotificationService::dispatch(
                    kind: $approve ? 'info' : 'warning', severity: 'medium', module: 'vendor-risk',
                    type: 'tprm.out_of_scope_'.$data['action'], recipient: 'user:'.$deciderId, orgId: $orgId,
                    title: $approve
                        ? "Lingkup PDP disetujui: {$vendor->name}"
                        : "Lingkup PDP ditolak: {$vendor->name}",
                    body: $approve
                        ? 'Pihak ketiga ditetapkan Di Luar Lingkup PDP — tidak memerlukan asesmen penuh.'
                        : 'Keputusan Di Luar Lingkup ditolak DPO — pihak ketiga kembali Dalam Lingkup PDP.',
                    actionUrl: '/vendor-risk', metadata: ['record_id' => $vendor->id],
                );
            } catch (\Throwable $e) {
                Log::warning('tprm.out_of_scope decision notif failed: '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => $approve
                ? 'Disetujui — pihak ketiga ditetapkan Di Luar Lingkup PDP.'
                : 'Ditolak — pihak ketiga kembali Dalam Lingkup PDP dan memerlukan asesmen penuh.',
            'data' => [
                'vendor_pdp_scope_status' => $vendor->fresh()->pdp_scope_status,
                'pre_assessment' => $pre?->fresh(),
            ],
        ]);
    }

    /**
     * POST /vendor-risk/{vendorId}/pre-assessment/public-link
     * Issue a public token so the third party can fill the triage themselves
     * (mirror VendorRiskController::generatePublicLink). Reuses the public-token
     * column convention; gated by PublicPreAssessmentTokenMiddleware.
     */
    public function publicLink(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        // Reuse an open (non-decided) row, else create one.
        $pre = VendorPreAssessment::forOrg($orgId)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [VendorPreAssessment::STATUS_DRAFT, VendorPreAssessment::STATUS_SUBMITTED])
            ->orderByDesc('created_at')
            ->first();

        if (! $pre) {
            $pre = VendorPreAssessment::create([
                'org_id' => $orgId,
                'vendor_id' => $vendor->id,
                'answers' => [],
                'status' => VendorPreAssessment::STATUS_DRAFT,
            ]);
        }

        $expiryDays = (int) config('vendor_screening.public_link_expiry_days', self::DEFAULT_TOKEN_EXPIRY_DAYS);
        if ($expiryDays <= 0) {
            $expiryDays = self::DEFAULT_TOKEN_EXPIRY_DAYS;
        }

        $token = (string) Str::uuid7();
        $pre->forceFill([
            'assessment_token' => $token,
            'token_expires_at' => now()->addDays($expiryDays),
            'token_consumed_at' => null,
        ])->save();

        $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:3000'));
        $publicUrl = rtrim((string) $baseUrl, '/').'/pra-asesmen-pihak-ketiga/'.$token;

        AuditLog::log('tprm.pre_assessment', $pre->id, 'generate_public_link', [
            'vendor_id' => $vendor->id,
            'token_prefix' => substr($token, 0, 8),
            'expires_at' => optional($pre->token_expires_at)->toIso8601String(),
        ], 'pre_assessment');

        return response()->json([
            'message' => 'Tautan penyaringan berhasil dibuat. Bagikan kepada pihak ketiga.',
            'pre_assessment_id' => $pre->id,
            'token' => $token,
            'public_url' => $publicUrl,
            'expires_at' => $pre->token_expires_at,
        ]);
    }

    private function scopeLabel(?string $scope): string
    {
        return match ($scope) {
            VendorPreAssessment::SCOPE_IN => 'Dalam Lingkup PDP',
            VendorPreAssessment::SCOPE_OUT => 'Di Luar Lingkup PDP',
            default => 'belum ditentukan',
        };
    }
}
