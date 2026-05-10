<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * HMAC-SHA256 signature verification untuk incoming webhooks.
 *
 * Industry standard pattern:
 *   - Vendor sign payload dengan shared secret → HMAC-SHA256
 *   - Kirim hex digest di header `X-Webhook-Signature: sha256=<hex>`
 *   - Optional: header `X-Webhook-Timestamp: <unix>` untuk anti-replay
 *
 * Privasimu verify:
 *   1. Hitung ulang HMAC dari raw body + secret
 *   2. hash_equals (constant-time compare) signature
 *   3. Kalau timestamp ada, reject kalau di luar tolerance window
 *
 * Backward compat: kalau settings webhook_hmac_required=false (default),
 * verifyRequest cuma return true tanpa cek apa-apa. Vendor existing
 * tetap jalan dengan shared-secret behavior di controller.
 */
class WebhookSignatureService
{
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';

    /**
     * Compute HMAC-SHA256 signature untuk payload. Hex digest, optional
     * prefix "sha256=" sesuai konvensi GitHub/Stripe.
     */
    public function sign(string $payload, string $secret, bool $withPrefix = true): string
    {
        $hex = hash_hmac('sha256', $payload, $secret);
        return $withPrefix ? "sha256={$hex}" : $hex;
    }

    /**
     * Verify incoming request. Strategi:
     *
     *   - Kalau setting `webhook_hmac_required` = true:
     *     SIGNATURE header WAJIB ada dan valid. Reject kalau missing/mismatch.
     *
     *   - Kalau setting = false:
     *     SIGNATURE optional. Kalau header dikirim → verify (tetap reject kalau
     *     mismatch). Kalau gak dikirim → pass (caller masih harus verify
     *     shared-secret terpisah).
     *
     *   - Timestamp tolerance: kalau header X-Webhook-Timestamp ada, reject
     *     kalau drift > tolerance. Kalau gak ada → skip check.
     */
    public function verifyRequest(Request $request, string $secret): array
    {
        $required = (bool) config('security.webhook_hmac_required', false);
        $signature = $request->header(self::HEADER_SIGNATURE);
        $timestamp = $request->header(self::HEADER_TIMESTAMP);

        // Timestamp check (only kalau header ada)
        if ($timestamp !== null) {
            $tolerance = (int) config('security.webhook_timestamp_tolerance_seconds', 300);
            $ts = (int) $timestamp;
            $drift = abs(time() - $ts);
            if ($ts <= 0 || $drift > $tolerance) {
                return [
                    'ok' => false,
                    'reason' => 'timestamp_out_of_window',
                    'drift_seconds' => $drift,
                    'tolerance_seconds' => $tolerance,
                ];
            }
        }

        // Signature check
        if ($signature === null || $signature === '') {
            if ($required) {
                return ['ok' => false, 'reason' => 'signature_missing'];
            }
            return ['ok' => true, 'reason' => 'signature_optional_skipped'];
        }

        // Sign over the canonical payload — kalau timestamp dikirim, ikutkan
        // dalam string yang di-sign supaya tidak bisa di-replay dengan timestamp
        // forged. Format: `<timestamp>.<body>` (sesuai konvensi Stripe).
        $body = $request->getContent();
        $payload = ($timestamp !== null) ? "{$timestamp}.{$body}" : $body;

        $expected = $this->sign($payload, $secret, true);
        $expectedNoPrefix = $this->sign($payload, $secret, false);

        // Terima signature dengan ATAU tanpa prefix "sha256="
        if (hash_equals($expected, $signature) || hash_equals($expectedNoPrefix, $signature)) {
            return ['ok' => true, 'reason' => 'verified'];
        }

        return ['ok' => false, 'reason' => 'signature_mismatch'];
    }
}
