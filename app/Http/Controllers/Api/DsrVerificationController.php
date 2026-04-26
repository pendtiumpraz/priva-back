<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DsrVerificationMail;
use App\Models\AuditLog;
use App\Models\DsrRequest;
use App\Services\DsrEventBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * DPO actions for DSR verification — used when subject can't or won't verify
 * via the email link (no SMTP configured, lost email, etc).
 *
 * Routes:
 *   POST /api/dsr/{id}/resend-verification — re-issue token + send email,
 *        return URL for manual delivery
 *   POST /api/dsr/{id}/manual-verify — DPO bypass with reason, audit logged
 */
class DsrVerificationController extends Controller
{
    public function __construct(private DsrEventBroadcaster $events) {}

    /**
     * POST /api/dsr/{id}/resend-verification
     * Generate fresh token + send mail + return URL.
     */
    public function resend(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->with('app')->findOrFail($id);

        if ($dsr->verification_status === 'verified') {
            return response()->json(['error' => 'Subject sudah verified.'], 422);
        }
        if (in_array($dsr->status, ['completed', 'rejected', 'cancelled', 'closed'], true)) {
            return response()->json(['error' => 'DSR sudah final, tidak bisa di-resend verification.'], 422);
        }

        $newToken = Str::random(64);
        $dsr->update([
            'verification_token' => $newToken,
            'verification_expires_at' => now()->addHours(24),
            'verification_status' => 'pending',
            'status' => 'pending_verification',
        ]);

        $verifyUrl = url("/public/dsr/verify/{$newToken}");

        $emailDispatched = false;
        if ($dsr->requester_email) {
            try {
                Mail::to($dsr->requester_email)->queue(new DsrVerificationMail($dsr, $verifyUrl, $dsr->app));
                $emailDispatched = true;
            } catch (\Throwable $e) {
                Log::warning("DSR resend mail failed for {$dsr->request_id}: " . $e->getMessage());
            }
        }

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.resend_verification',
            'details' => [
                'email_dispatched' => $emailDispatched,
                'expires_at' => $dsr->verification_expires_at->toIso8601String(),
            ],
        ]);

        return response()->json([
            'message' => $emailDispatched
                ? 'Email verifikasi telah dikirim ulang. Link valid 24 jam.'
                : 'Token diperbarui. Email tidak terkirim — gunakan URL di bawah untuk delivery manual.',
            'verify_url' => $verifyUrl,
            'expires_at' => $dsr->verification_expires_at,
            'email_dispatched' => $emailDispatched,
        ]);
    }

    /**
     * POST /api/dsr/{id}/manual-verify
     * DPO bypass — mark verified tanpa subject klik link.
     * Pakai HANYA kalau:
     *  - SMTP tidak available + DPO sudah konfirmasi identitas via channel lain (telepon, in-person)
     *  - Subject sudah verified di sistem klien lain (e.g. login KYC)
     *  - Subject permintaan dipindah dari channel non-digital (CS langsung)
     *
     * Wajib reason — di-log untuk audit Kemenkominfo.
     */
    public function manualVerify(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        if ($dsr->verification_status === 'verified') {
            return response()->json(['error' => 'Sudah verified.'], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'verified_via' => 'nullable|string|max:64', // 'phone' | 'in_person' | 'kyc_external' | 'cs_call' | 'other'
        ]);

        $dsr->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'status' => 'pending_review',
            'verification_token' => null,
            'verification_method' => 'dpo_manual:' . ($data['verified_via'] ?? 'other'),
        ]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.manual_verify',
            'details' => [
                'verified_by_user_id' => $user->id,
                'verified_by_email' => $user->email,
                'verified_via' => $data['verified_via'] ?? 'other',
                'reason' => $data['reason'],
                'ip' => $request->ip(),
            ],
        ]);

        $this->events->emit(DsrEventBroadcaster::EVENT_VERIFIED, $dsr->fresh());

        return response()->json([
            'message' => 'DSR berhasil di-verify manual oleh DPO. Status → pending_review.',
            'data' => $dsr->fresh(),
        ]);
    }
}
