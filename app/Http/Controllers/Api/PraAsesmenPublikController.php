<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorPreAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Endpoint publik (tanpa login) untuk pihak ketiga mengisi triage
 * Pre-Assessment ("Penyaringan Lingkup PDP").
 *
 * Akses di-gate oleh PublicPreAssessmentTokenMiddleware yang sudah:
 *   - resolve pre-assessment dari token UUID di URL,
 *   - validasi expiry + single-use guard untuk write,
 *   - set CurrentOrgContext ke org_id pre-assessment,
 *   - inject row ke `$request->_pre_assessment`.
 *
 *   GET  /pra-asesmen-publik/{token}         → vendor name + triage questions + saved answers
 *   POST /pra-asesmen-publik/{token}/submit  → simpan jawaban, hitung suggested_scope,
 *                                              catat ip/ua, consume token.
 *
 * Submit TIDAK auto-decide — reviewer internal tetap memutuskan via
 * VendorPreAssessmentController::decide. Pihak ketiga hanya menyediakan input.
 */
class PraAsesmenPublikController extends Controller
{
    public function show(Request $request, string $token)
    {
        /** @var VendorPreAssessment $pre */
        $pre = $request->get('_pre_assessment');
        $vendor = Vendor::withTrashed()->find($pre->vendor_id);
        $org = Organization::find($pre->org_id);

        // Questions tanpa flag is_core — pihak ketiga tidak perlu tahu mana yang decisive.
        $questions = collect(VendorPreAssessment::effectiveQuestions($pre->org_id))
            ->map(fn ($q) => [
                'question_code' => $q['question_code'],
                'text' => $q['text'],
                'description' => $q['description'] ?? null,
            ])
            ->values();

        return response()->json([
            'data' => [
                'pre_assessment' => [
                    'id' => $pre->id,
                    'status' => $pre->status,
                    'is_locked' => $pre->token_consumed_at !== null,
                    'token_expires_at' => optional($pre->token_expires_at)->toIso8601String(),
                ],
                'vendor' => $vendor ? ['id' => $vendor->id, 'name' => $vendor->name] : null,
                'organization' => $org ? [
                    'name' => $org->name ?? null,
                    'logo_url' => $org->logo_url ?? null,
                ] : null,
                'questions' => $questions,
                'answers' => is_array($pre->answers) ? $pre->answers : [],
            ],
        ]);
    }

    /**
     * POST /pra-asesmen-publik/{token}/submit
     * Pihak ketiga submit jawaban triage. Hitung suggested_scope, status →
     * submitted, catat ip/ua, consume token. TIDAK menyentuh vendor scope —
     * reviewer internal yang memutuskan via /decide.
     */
    public function submit(Request $request, string $token)
    {
        /** @var VendorPreAssessment $pre */
        $pre = $request->get('_pre_assessment');

        $request->validate([
            'answers' => 'required|array',
            'answers.*' => ['nullable', Rule::in(['ya', 'tidak'])],
        ]);

        if ($pre->token_consumed_at !== null) {
            return response()->json(['error' => 'Penyaringan sudah dikirim sebelumnya.'], 410);
        }

        $existing = is_array($pre->answers) ? $pre->answers : [];
        $merged = array_merge($existing, $request->input('answers'));
        $suggested = VendorPreAssessment::suggestScope($merged, $pre->org_id);

        DB::transaction(function () use ($pre, $request, $merged, $suggested) {
            $pre->forceFill([
                'answers' => $merged,
                'suggested_scope' => $suggested,
                'status' => VendorPreAssessment::STATUS_SUBMITTED,
                'filled_by' => VendorPreAssessment::FILLED_PUBLIC,
                'token_consumed_at' => now(),
                'submitted_at' => now(),
                'submitted_ip' => substr((string) $request->ip(), 0, 45),
                'submitted_user_agent' => $request->userAgent(),
            ])->save();

            AuditLog::create([
                'org_id' => $pre->org_id,
                'module' => 'tprm.pre_assessment_public_submit',
                'record_id' => $pre->id,
                'action' => 'public_submit',
                'user_id' => null,
                'user_name' => 'Public Token',
                'user_role' => 'public_token',
                'section' => 'pre_assessment',
                'changes' => [
                    'token_prefix' => substr((string) $pre->assessment_token, 0, 8),
                    'suggested_scope' => $suggested,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Penyaringan berhasil dikirim. Terima kasih — tim terkait akan meninjau hasilnya.',
            'data' => [
                'pre_assessment_id' => $pre->id,
                'submitted_at' => optional($pre->fresh()->submitted_at)->toIso8601String(),
            ],
        ]);
    }
}
