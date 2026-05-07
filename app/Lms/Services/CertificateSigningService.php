<?php

namespace App\Lms\Services;

/**
 * Signs LMS certificate payloads using the same private key + algorithm as
 * the existing license signing flow (see `licenses.signed_payload`).
 *
 * Output format: json || '.' || base64url(signature)
 *
 * The JSON body is stored verbatim (not base64-encoded) so that the payload
 * remains human-readable and can be tampered-with-detection in tests. The
 * HMAC still covers the raw JSON body, so any mutation is detected.
 *
 * If the project later extracts a shared `App\Services\Crypto\PayloadSigner`,
 * this class should be refactored to delegate. For Foundation, we self-contain
 * the signing logic to avoid blocking on that refactor.
 */
class CertificateSigningService
{
    public function sign(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig  = $this->b64u($this->rawSign($body));
        return $body . '.' . $sig;
    }

    public function verify(string $signed): bool
    {
        $lastDot = strrpos($signed, '.');
        if ($lastDot === false) {
            return false;
        }
        $body = substr($signed, 0, $lastDot);
        $sig  = substr($signed, $lastDot + 1);
        $expected = $this->b64u($this->rawSign($body));
        return hash_equals($expected, $sig);
    }

    public function decode(string $signed): ?array
    {
        if (! $this->verify($signed)) {
            return null;
        }
        $lastDot = strrpos($signed, '.');
        $json = substr($signed, 0, $lastDot);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function rawSign(string $message): string
    {
        $key = config('app.key');
        return hash_hmac('sha256', $message, (string) $key, true);
    }

    private function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64uDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
