<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DsrVerificationMail;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Services\CaptchaVerifier;
use App\Services\DsrEventBroadcaster;
use App\Services\TenantStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Public DSR endpoints (no auth) — dipakai oleh embed widget di klien websites.
 *
 * Routes:
 *   GET  /public/dsr/config/{embed_token}              — widget config
 *   POST /public/dsr/submit/{embed_token}              — submit DSR (with captcha)
 *   GET  /public/dsr/verify/{token}                    — HTML page (browser link)
 *   GET  /public/dsr/{verification_token}/nda          — NDA HTML preview
 *   POST /public/dsr/{verification_token}/nda/sign     — e-sign NDA
 */
class DsrPublicController extends Controller
{
    public function __construct(
        private CaptchaVerifier $captcha,
        private TenantStorageService $storage,
        private DsrEventBroadcaster $events,
    ) {}

    /**
     * GET /public/dsr/config/{embed_token}
     * Widget calls this to render correct button text + branding + captcha key.
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
            'requires_nda_for_access' => (bool) ($app->requires_nda_for_access ?? false),
            'captcha' => $app->captcha_provider ? [
                'provider' => $app->captcha_provider,
                'site_key' => $app->captcha_site_key,
            ] : null,
        ])->header('Access-Control-Allow-Origin', $this->originFor($request, $app))
          ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type');
    }

    /**
     * POST /public/dsr/submit/{embed_token}
     */
    public function submit(Request $request, string $embedToken)
    {
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
            'captcha_token' => 'nullable|string|max:4000',
        ]);

        // Captcha — only enforced if provider configured
        if (!$this->captcha->verifyForApp($app, $data['captcha_token'] ?? null, $request->ip())) {
            return response()->json([
                'error' => 'Verifikasi captcha gagal. Silakan refresh dan coba lagi.',
            ], 422);
        }

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
            'deadline_at' => now()->addHours(72),
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
                'captcha_used' => (bool) ($app->captcha_provider),
            ],
        ]);

        $verifyUrl = url("/public/dsr/verify/{$verificationToken}");

        // Auto-seed scopes from app's default Information Systems (so DPO doesn't
        // need to manually pick at Scope tab — already pre-populated when they open).
        $this->seedScopesFromApp($dsr, $app);

        $emailDispatched = $this->dispatchVerificationMail($dsr, $app, $verifyUrl);

        $this->events->emit(DsrEventBroadcaster::EVENT_CREATED, $dsr);

        return response()->json([
            'message' => 'Permintaan diterima. Silakan cek email Anda untuk verifikasi (link valid 24 jam).',
            'request_id' => $dsr->request_id,
            'verification_required' => true,
            'email_dispatched' => $emailDispatched,
            // Surfaced for DPO UI fallback when SMTP not configured (mail driver=log)
            // — DPO can copy to send via WhatsApp/SMS manually.
            '_dev_verify_url' => $verifyUrl,
        ], 202);
    }

    /**
     * Seed DsrRequestScope from app.default_information_system_ids.
     * No-op if app missing or has no defaults. Wrapped so failure doesn't block submit.
     */
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
            \Log::warning("Auto-seed scope failed for DSR {$dsr->request_id}: " . $e->getMessage());
        }
    }

    /**
     * Try to send verification email. Wrapped in try-catch so misconfigured SMTP
     * doesn't 500 the public submit endpoint. When mail driver = log, this still
     * "succeeds" but body lands in laravel.log; caller should also surface URL.
     */
    private function dispatchVerificationMail(DsrRequest $dsr, ?DsrApp $app, string $verifyUrl): bool
    {
        if (empty($dsr->requester_email)) return false;
        try {
            Mail::to($dsr->requester_email)->queue(new DsrVerificationMail($dsr, $verifyUrl, $app));
            return true;
        } catch (\Throwable $e) {
            Log::warning("DSR verification mail queue failed for {$dsr->request_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * GET /public/dsr/verify/{token}
     * Returns HTML page (subject open in browser via email link).
     * Returns JSON if Accept: application/json (widget polling).
     */
    public function verify(Request $request, string $token)
    {
        $wantsJson = $request->wantsJson() && !$request->acceptsHtml();
        $dsr = DsrRequest::where('verification_token', $token)->first();

        if (!$dsr) {
            return $this->verifyResponse($wantsJson, 'invalid', null, [
                'message' => 'Link verifikasi tidak ditemukan atau sudah pernah dipakai.',
            ]);
        }

        if ($dsr->verification_expires_at && Carbon::now()->gt($dsr->verification_expires_at)) {
            return $this->verifyResponse($wantsJson, 'expired', $dsr);
        }

        // Idempotent: kalau already verified, just show success
        if ($dsr->verification_status !== 'verified') {
            $dsr->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'status' => 'pending_review',
                // Keep verification_token for NDA flow if NDA required
                // (cleared after NDA signed or after 24h expiry job)
            ]);

            AuditLog::create([
                'org_id' => $dsr->org_id,
                'user_id' => null,
                'module' => 'dsr',
                'record_id' => $dsr->id,
                'action' => 'dsr.verify',
                'details' => ['ip' => $request->ip()],
            ]);

            $this->events->emit(DsrEventBroadcaster::EVENT_VERIFIED, $dsr->fresh());
        }

        return $this->verifyResponse($wantsJson, 'verified', $dsr);
    }

    /**
     * GET /public/dsr/{verification_token}/nda
     * Show NDA preview (HTML) before subject signs.
     */
    public function ndaPreview(Request $request, string $token)
    {
        $dsr = $this->resolveDsrByVerificationToken($token);
        if (!$dsr) abort(404, 'Link tidak valid atau sudah kedaluwarsa.');

        $app = $dsr->app;
        if (!$app || !$app->requires_nda_for_access) {
            abort(422, 'NDA tidak diperlukan untuk permintaan ini.');
        }
        if ($dsr->nda_signed_at) {
            abort(409, 'NDA untuk permintaan ini sudah ditandatangani.');
        }

        $org = Organization::findOrFail($dsr->org_id);

        return response()->view('public.dsr.nda_preview', [
            'dsr' => $dsr,
            'app' => $app,
            'org' => $org,
            'orgName' => $org->name,
            'branding' => $app->branding ?? [],
            'signUrl' => url("/public/dsr/{$token}/nda/sign"),
            'ndaText' => $this->ndaText($dsr, $org),
        ]);
    }

    /**
     * POST /public/dsr/{verification_token}/nda/sign
     * Subject e-signs NDA → generate signed PDF, store as Document.
     */
    public function ndaSign(Request $request, string $token)
    {
        $dsr = $this->resolveDsrByVerificationToken($token);
        if (!$dsr) abort(404, 'Link tidak valid atau sudah kedaluwarsa.');
        if ($dsr->nda_signed_at) abort(409, 'NDA sudah ditandatangani.');

        $app = $dsr->app;
        if (!$app || !$app->requires_nda_for_access) {
            abort(422, 'NDA tidak diperlukan.');
        }

        $data = $request->validate([
            'full_name' => 'required|string|max:200',
            'agree' => 'accepted',
            'typed_signature' => 'required|string|max:200',
        ]);

        $org = Organization::findOrFail($dsr->org_id);
        $signedAt = now();

        $payload = [
            'org' => $org,
            'orgName' => $org->name,
            'orgWebsite' => $org->website ?? null,
            'orgLogoUrl' => $org->logo_url ?? null,
            'dsr' => $dsr,
            'signerName' => $data['full_name'],
            'signerEmail' => $dsr->requester_email,
            'typedSignature' => $data['typed_signature'],
            'signedAt' => $signedAt,
            'signedIp' => $request->ip(),
            'userAgent' => mb_substr((string) $request->userAgent(), 0, 300),
            'ndaText' => $this->ndaText($dsr, $org),
            'verificationStamp' => strtoupper(substr(hash('sha256', $dsr->id . '|nda|' . $signedAt), 0, 16)),
        ];

        $pdf = Pdf::loadView('reports.dsr.nda_signed', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);

        $bytes = $pdf->output();
        $disk = $this->storage->getDisk($org);
        $path = "tenants/{$org->id}/dsr/{$dsr->id}/nda/signed-" . time() . '.pdf';
        $disk->put($path, $bytes);

        $doc = Document::create([
            'org_id' => $org->id,
            'kind' => 'dsr.nda_signed',
            'source_type' => 'dsr_request',
            'source_id' => $dsr->id,
            'name' => "nda-signed-{$dsr->request_id}.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'storage_path' => $path,
            'storage_driver' => $org->storage_driver ?: 'local',
            'metadata' => [
                'signer_name' => $data['full_name'],
                'signer_email' => $dsr->requester_email,
                'signed_at' => $signedAt->toIso8601String(),
                'signed_ip' => $request->ip(),
                'verification_stamp' => $payload['verificationStamp'],
            ],
        ]);

        $dsr->update([
            'nda_signed_at' => $signedAt,
            'nda_signed_doc_id' => $doc->id,
            'verification_token' => null, // burn after NDA signed
        ]);

        AuditLog::create([
            'org_id' => $org->id,
            'user_id' => null,
            'module' => 'dsr',
            'record_id' => $dsr->id,
            'action' => 'dsr.nda_signed',
            'details' => [
                'document_id' => $doc->id,
                'ip' => $request->ip(),
            ],
        ]);

        $this->events->emit(DsrEventBroadcaster::EVENT_NDA_SIGNED, $dsr->fresh(), [
            'document_id' => $doc->id,
        ]);

        return response()->view('public.dsr.nda_thanks', [
            'dsr' => $dsr->fresh(),
            'orgName' => $org->name,
            'branding' => $app->branding ?? [],
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resolveApp(string $embedToken): ?DsrApp
    {
        $cacheKey = 'dsr_app:' . $embedToken;
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($embedToken) {
            return DsrApp::where('embed_token', $embedToken)->first();
        });
    }

    private function resolveDsrByVerificationToken(string $token): ?DsrRequest
    {
        return DsrRequest::with('app')
            ->where('verification_token', $token)
            ->where(function ($q) {
                $q->whereNull('verification_expires_at')
                  ->orWhere('verification_expires_at', '>', now());
            })
            ->first();
    }

    private function verifyDomain(Request $request, DsrApp $app): void
    {
        $allowed = $app->allowed_domains ?? [];
        if (empty($allowed)) return;

        $origin = $request->header('Origin') ?: $request->header('Referer');
        if (!$origin) return;

        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;

        foreach ($allowed as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') continue;
            if ($pattern === '*' || $host === $pattern) return;
            if (str_starts_with($pattern, '*.')) {
                $base = mb_substr($pattern, 2);
                if (str_ends_with($host, $base)) return;
            }
        }

        abort(403, 'Origin not allowed for this DSR app');
    }

    private function originFor(Request $request, DsrApp $app): string
    {
        $allowed = $app->allowed_domains ?? [];
        if (empty($allowed)) return '*';

        $origin = $request->header('Origin');
        if (!$origin) return '*';

        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;
        foreach ($allowed as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '*' || $host === $pattern) return $origin;
            if (str_starts_with($pattern, '*.') && str_ends_with($host, mb_substr($pattern, 2))) {
                return $origin;
            }
        }
        return '*';
    }

    private function verifyResponse(bool $wantsJson, string $status, ?DsrRequest $dsr, array $extras = [])
    {
        if ($wantsJson) {
            return response()->json(array_merge([
                'status' => $status,
                'request_id' => $dsr?->request_id,
                'deadline_at' => $dsr?->deadline_at,
            ], $extras), $status === 'verified' ? 200 : ($status === 'expired' ? 410 : 404));
        }

        $needsNda = false;
        $ndaUrl = null;
        if ($status === 'verified' && $dsr && $dsr->app?->requires_nda_for_access
            && in_array($dsr->request_type, ['access', 'portability'], true)
            && !$dsr->nda_signed_at && $dsr->verification_token) {
            $needsNda = true;
            $ndaUrl = url("/public/dsr/{$dsr->verification_token}/nda");
        }

        return response()->view('public.dsr.verify_result', [
            'status' => $status,
            'title' => match ($status) {
                'verified' => 'Identitas Terverifikasi',
                'expired' => 'Link Kedaluwarsa',
                default => 'Link Tidak Valid',
            },
            'appName' => $dsr?->app?->name ?? ($extras['app_name'] ?? null),
            'requestId' => $dsr?->request_id,
            'deadlineAt' => $dsr?->deadline_at?->setTimezone('Asia/Jakarta')->format('d F Y H:i') . ' WIB',
            'branding' => $dsr?->app?->branding ?? [],
            'ndaRequired' => $needsNda,
            'ndaUrl' => $ndaUrl,
            'message' => $extras['message'] ?? null,
        ], $status === 'verified' ? 200 : ($status === 'expired' ? 410 : 404));
    }

    private function ndaText(DsrRequest $dsr, Organization $org): string
    {
        return "Saya, sebagai pemohon hak subjek data dengan nomor permintaan "
             . "{$dsr->request_id}, dengan ini menyatakan dan menyetujui bahwa data pribadi "
             . "yang akan saya terima dari {$org->name} adalah informasi RAHASIA. "
             . "Saya BERJANJI:\n\n"
             . "1. Tidak menyalin, menyebarkan, mempublikasikan, atau membagikan data tersebut "
             . "kepada pihak ketiga tanpa izin tertulis dari {$org->name}.\n\n"
             . "2. Hanya menggunakan data tersebut untuk kepentingan pribadi sebagai subjek data "
             . "yang sah, sesuai hak yang diberikan UU PDP No. 27 Tahun 2022.\n\n"
             . "3. Memberitahu {$org->name} segera apabila terjadi kebocoran tidak disengaja "
             . "atas data yang saya terima.\n\n"
             . "4. Memahami bahwa pelanggaran atas perjanjian ini dapat mengakibatkan tuntutan "
             . "hukum perdata maupun pidana sesuai peraturan yang berlaku di Republik Indonesia.\n\n"
             . "Tanda tangan elektronik di bawah ini sah dan mengikat secara hukum berdasarkan "
             . "UU ITE No. 11 Tahun 2008 dan perubahannya.";
    }
}
