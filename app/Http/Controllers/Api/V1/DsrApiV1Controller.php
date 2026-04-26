<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\DsrVerificationMail;
use App\Models\AuditLog;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\InformationSystem;
use App\Services\DsrEventBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * DSR Partner API V1 — server-to-server (alternatif widget embed).
 *
 * Auth: middleware `dsr.api_key` (X-Privasimu-Client-Key + X-Privasimu-Signature HMAC).
 * Klien backend POST ke endpoint ini setelah subject submit form di UI klien sendiri.
 *
 * Endpoints:
 *   POST /api/v1/dsr/submit              — create DSR (skip captcha; verify still required by default)
 *   POST /api/v1/dsr/submit-prevoerified — create DSR + auto-verify (klien sudah KYC subject di sistem mereka)
 *   GET  /api/v1/dsr/{request_id}/status — pull status DSR (polling)
 */
class DsrApiV1Controller extends Controller
{
    public function __construct(private DsrEventBroadcaster $events) {}

    /**
     * POST /api/v1/dsr/submit
     * Submit DSR. Subject WAJIB verifikasi via OTP email yang Privasimu kirim.
     */
    public function submit(Request $request)
    {
        return $this->createDsr($request, autoVerify: false);
    }

    /**
     * POST /api/v1/dsr/submit-preverified
     * Klien yang sudah verify identitas subject sendiri (e.g. via login session, KYC) bisa
     * skip step OTP email. Wajib include `verified_via` field untuk audit trail.
     */
    public function submitPreverified(Request $request)
    {
        return $this->createDsr($request, autoVerify: true);
    }

    /**
     * GET /api/v1/dsr/{request_id}/status
     * Pull status — useful kalau klien polling untuk update UI mereka.
     */
    public function status(Request $request, string $requestId)
    {
        $app = $request->dsrApp;
        $dsr = DsrRequest::where('app_id', $app->id)
            ->where('request_id', $requestId)
            ->with(['scopes', 'executions'])
            ->first();

        if (!$dsr) return response()->json(['error' => 'DSR not found'], 404);

        return response()->json([
            'request_id' => $dsr->request_id,
            'status' => $dsr->status,
            'verification_status' => $dsr->verification_status,
            'request_type' => $dsr->request_type,
            'created_at' => $dsr->created_at?->toIso8601String(),
            'verified_at' => $dsr->verified_at?->toIso8601String(),
            'deadline_at' => $dsr->deadline_at?->toIso8601String(),
            'closed_at' => $dsr->closed_at?->toIso8601String(),
            'scope_count' => $dsr->scopes->count(),
            'execution_summary' => [
                'total' => $dsr->executions->count(),
                'pending' => $dsr->executions->where('status', 'pending')->count(),
                'executed' => $dsr->executions->where('status', 'executed')->count(),
                'failed' => $dsr->executions->where('status', 'failed')->count(),
                'skipped' => $dsr->executions->where('status', 'skipped')->count(),
            ],
        ]);
    }

    // =====================================================================
    private function createDsr(Request $request, bool $autoVerify): mixed
    {
        $app = $request->dsrApp;
        if (!$app) return response()->json(['error' => 'App context not resolved'], 500);

        $rules = [
            'request_type' => 'required|in:' . implode(',', DsrRequest::REQUEST_TYPES),
            'requester_name' => 'required|string|max:200',
            'requester_email' => 'required|email|max:200',
            'requester_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:5000',
            'subject_data' => 'nullable|array',
            'subject_data.nik' => 'nullable|string|max:20',
            'subject_data.customer_id' => 'nullable|string|max:100',
            'external_reference' => 'nullable|string|max:100', // klien's internal ticket id
        ];
        if ($autoVerify) {
            $rules['verified_via'] = 'required|string|max:64'; // 'kyc_login' | 'cs_call' | 'in_person' | etc
            $rules['verified_reason'] = 'required|string|min:10|max:500';
        }
        $data = $request->validate($rules);

        // Anti-duplicate
        $existing = DsrRequest::where('org_id', $app->org_id)
            ->where('app_id', $app->id)
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'closed'])
            ->where('requester_email', $data['requester_email'])
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Active DSR already exists for this email + app.',
                'existing_request_id' => $existing->request_id,
                'status' => $existing->status,
            ], 409);
        }

        $year = date('Y');
        $count = DsrRequest::where('org_id', $app->org_id)->whereYear('created_at', $year)->count() + 1;
        $requestId = sprintf('DSR-%s-%03d', $year, $count);

        $verificationToken = $autoVerify ? null : Str::random(64);
        $now = now();

        $dsr = DsrRequest::create([
            'org_id' => $app->org_id,
            'app_id' => $app->id,
            'request_id' => $requestId,
            'request_type' => $data['request_type'],
            'requester_name' => $data['requester_name'],
            'requester_email' => $data['requester_email'],
            'requester_phone' => $data['requester_phone'] ?? null,
            'description' => $data['description'] ?? null,
            'subject_data' => array_filter([
                'nik' => $data['subject_data']['nik'] ?? null,
                'customer_id' => $data['subject_data']['customer_id'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
            ]),
            'status' => $autoVerify ? 'pending_review' : 'pending_verification',
            'verification_status' => $autoVerify ? 'verified' : 'pending',
            'verification_token' => $verificationToken,
            'verification_expires_at' => $autoVerify ? null : $now->copy()->addHours(24),
            'verification_method' => $autoVerify
                ? ('partner_api:' . ($data['verified_via'] ?? 'unknown'))
                : 'email_otp',
            'verified_at' => $autoVerify ? $now : null,
            'deadline_at' => $now->copy()->addHours(72),
            'assigned_to' => $app->default_assignee_user_id,
        ]);

        // Auto-seed scopes from app defaults
        $this->seedScopesFromApp($dsr, $app);

        AuditLog::create([
            'org_id' => $app->org_id, 'user_id' => null,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => $autoVerify ? 'dsr.api_submit_preverified' : 'dsr.api_submit',
            'details' => array_filter([
                'app_id' => $app->id,
                'app_code' => $app->app_code,
                'auth_method' => 'api_key',
                'request_type' => $data['request_type'],
                'external_reference' => $data['external_reference'] ?? null,
                'verified_via' => $autoVerify ? ($data['verified_via'] ?? null) : null,
                'verified_reason' => $autoVerify ? ($data['verified_reason'] ?? null) : null,
                'ip' => $request->ip(),
            ]),
        ]);

        // Verification email — only when not autoVerify
        $emailDispatched = false;
        $verifyUrl = null;
        if (!$autoVerify && $verificationToken) {
            $verifyUrl = url("/public/dsr/verify/{$verificationToken}");
            try {
                Mail::to($dsr->requester_email)->queue(new DsrVerificationMail($dsr, $verifyUrl, $app));
                $emailDispatched = true;
            } catch (\Throwable $e) {
                Log::warning("DSR API submit mail failed for {$dsr->request_id}: " . $e->getMessage());
            }
        }

        $this->events->emit(
            $autoVerify ? DsrEventBroadcaster::EVENT_VERIFIED : DsrEventBroadcaster::EVENT_CREATED,
            $dsr
        );

        return response()->json(array_filter([
            'request_id' => $dsr->request_id,
            'status' => $dsr->status,
            'verification_status' => $dsr->verification_status,
            'verification_required' => !$autoVerify,
            'email_dispatched' => $autoVerify ? null : $emailDispatched,
            'verify_url' => $verifyUrl, // null kalau preverified
            'deadline_at' => $dsr->deadline_at?->toIso8601String(),
        ], fn($v) => $v !== null), 201);
    }

    private function seedScopesFromApp(DsrRequest $dsr, DsrApp $app): void
    {
        try {
            $defaultIds = $app->default_information_system_ids ?? [];
            if (empty($defaultIds)) return;
            $validIs = InformationSystem::whereIn('id', $defaultIds)
                ->where('org_id', $app->org_id)
                ->get(['id', 'is_sharded', 'shards']);
            foreach ($validIs as $is) {
                DsrRequestScope::create([
                    'dsr_request_id' => $dsr->id,
                    'information_system_id' => $is->id,
                    'request_types' => [$dsr->request_type],
                    'shards_affected' => $is->is_sharded
                        ? collect($is->shards ?? [])->map(fn($s) => is_array($s) ? ($s['name'] ?? null) : $s)->filter()->values()->all()
                        : [],
                    'sql_pack_status' => 'pending',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Auto-seed scope failed for DSR {$dsr->request_id}: " . $e->getMessage());
        }
    }
}
