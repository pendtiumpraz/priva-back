<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Public DSR endpoints (no auth) — dipakai oleh embed widget di klien websites.
 *
 * Routes:
 *   GET  /public/dsr/config/{embed_token}   — widget config (button text, branding)
 *   POST /public/dsr/submit/{embed_token}   — submit DSR request
 *   GET  /public/dsr/verify/{token}         — verify subject identity via OTP link
 */
class DsrPublicController extends Controller
{
    /**
     * GET /public/dsr/config/{embed_token}
     * Widget calls this to render correct button text + branding.
     */
    public function config(Request $request, string $embedToken)
    {
        $app = $this->resolveApp($embedToken);
        if (!$app) {
            return response()->json(['error' => 'Invalid embed token'], 404);
        }

        $this->verifyDomain($request, $app);

        return response()->json([
            'app_name' => $app->name,
            'branding' => $app->branding ?? [],
            'request_types' => DsrRequest::REQUEST_TYPES,
            'requires_nda_for_access' => $app->requires_nda_for_access ?? false,
        ]);
    }

    /**
     * POST /public/dsr/submit/{embed_token}
     *
     * Body: {
     *   request_type, requester_name, requester_email, requester_phone?,
     *   description?, subject_data?, captcha_token?
     * }
     */
    public function submit(Request $request, string $embedToken)
    {
        // Rate limit: 5 submissions per IP per hour (anti-spam)
        $rateLimiterKey = 'dsr-submit:' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimiterKey, 5)) {
            $retryAfter = RateLimiter::availableIn($rateLimiterKey);
            return response()->json([
                'error' => 'Too many requests. Please try again in ' . $retryAfter . ' seconds.',
            ], 429);
        }
        RateLimiter::hit($rateLimiterKey, 3600);

        $app = $this->resolveApp($embedToken);
        if (!$app || !$app->is_active) {
            return response()->json(['error' => 'Invalid or inactive embed token'], 404);
        }

        $this->verifyDomain($request, $app);

        $data = $request->validate([
            'request_type' => 'required|in:' . implode(',', DsrRequest::REQUEST_TYPES),
            'requester_name' => 'required|string|max:200',
            'requester_email' => 'required|email|max:200',
            'requester_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:5000',
            'subject_data' => 'nullable|array',
            'subject_data.nik' => 'nullable|string|max:20',
            'subject_data.customer_id' => 'nullable|string|max:100',
        ]);

        // Anti-duplicate: 1 active DSR per email per app at a time
        $existing = DsrRequest::where('org_id', $app->org_id)
            ->where('app_id', $app->id)
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'closed'])
            ->where('requester_email', $data['requester_email'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah memiliki permintaan aktif untuk app ini. Mohon menunggu respons sebelum submit baru.',
                'existing_request_id' => $existing->request_id,
            ], 409);
        }

        // Generate request_id (DSR-YYYY-NNN)
        $year = date('Y');
        $count = DsrRequest::where('org_id', $app->org_id)
            ->whereYear('created_at', $year)
            ->count() + 1;
        $requestId = sprintf('DSR-%s-%03d', $year, $count);

        $verificationToken = Str::random(64);

        $dsr = DsrRequest::create([
            'org_id' => $app->org_id,
            'app_id' => $app->id,
            'request_id' => $requestId,
            'request_type' => $data['request_type'],
            'requester_name' => $data['requester_name'],
            'requester_email' => $data['requester_email'],
            'requester_phone' => $data['requester_phone'] ?? null,
            'description' => $data['description'] ?? null,
            'subject_data' => $data['subject_data'] ?? null,
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'verification_token' => $verificationToken,
            'verification_expires_at' => now()->addHours(24),
            'verification_method' => 'email_otp',
            'deadline_at' => now()->addHours(72),  // 72-jam SLA dari submission
            'assigned_to' => $app->default_assignee_user_id,
        ]);

        AuditLog::create([
            'org_id' => $app->org_id,
            'user_id' => null,
            'module' => 'dsr',
            'record_id' => $dsr->id,
            'action' => 'dsr.submit_public',
            'details' => [
                'app_id' => $app->id,
                'app_code' => $app->app_code,
                'request_type' => $data['request_type'],
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            ],
        ]);

        // TODO Phase 2: send verification email via klien's SMTP from dsr_settings
        // For now, return verification link in response untuk testing.
        $verifyUrl = url("/public/dsr/verify/{$verificationToken}");

        return response()->json([
            'message' => 'Permintaan diterima. Silakan cek email Anda untuk verifikasi (link valid 24 jam).',
            'request_id' => $dsr->request_id,
            'verification_required' => true,
            // TODO: hapus dari response saat email service aktif
            '_dev_verify_url' => $verifyUrl,
        ], 202);
    }

    /**
     * GET /public/dsr/verify/{token}
     * Subject klik link di email → verify identity.
     */
    public function verify(Request $request, string $token)
    {
        $dsr = DsrRequest::where('verification_token', $token)
            ->where('verification_status', 'pending')
            ->first();

        if (!$dsr) {
            return response()->json([
                'error' => 'Invalid or expired verification token',
            ], 404);
        }

        if ($dsr->verification_expires_at && Carbon::now()->gt($dsr->verification_expires_at)) {
            return response()->json([
                'error' => 'Verification link expired. Please submit a new request.',
            ], 410);
        }

        $dsr->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'status' => 'pending_review',
            'verification_token' => null,  // single-use
        ]);

        AuditLog::create([
            'org_id' => $dsr->org_id,
            'user_id' => null,
            'module' => 'dsr',
            'record_id' => $dsr->id,
            'action' => 'dsr.verify',
            'details' => ['ip' => $request->ip()],
        ]);

        // TODO Phase 2: notify DPO via email + Telegram

        return response()->json([
            'message' => 'Identitas Anda terverifikasi. DPO akan merespons dalam 72 jam.',
            'request_id' => $dsr->request_id,
            'deadline_at' => $dsr->deadline_at,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resolveApp(string $embedToken): ?DsrApp
    {
        // Cache 10 min — embed_token rarely rotates
        $cacheKey = 'dsr_app:' . $embedToken;
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($embedToken) {
            return DsrApp::where('embed_token', $embedToken)->first();
        });
    }

    /**
     * CORS-style domain check. Kalau allowed_domains diisi di app config,
     * Origin/Referer harus match. Wildcard "*.client.com" supported.
     */
    private function verifyDomain(Request $request, DsrApp $app): void
    {
        $allowed = $app->allowed_domains ?? [];
        if (empty($allowed)) return;  // no restriction

        $origin = $request->header('Origin') ?: $request->header('Referer');
        if (!$origin) return;  // direct API call (testing) — allow

        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;

        foreach ($allowed as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') continue;
            if ($pattern === '*' || $host === $pattern) return;
            // Wildcard *.example.com
            if (str_starts_with($pattern, '*.')) {
                $base = mb_substr($pattern, 2);
                if (str_ends_with($host, $base)) return;
            }
        }

        abort(403, 'Origin not allowed for this DSR app');
    }
}
