<?php

namespace App\Services;

use App\Models\DsrApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verify CAPTCHA tokens against the configured provider.
 * Cloudflare Turnstile, hCaptcha, reCAPTCHA v3 supported.
 *
 * Usage: $verifier->verifyForApp($app, $token, $ip)
 *  → returns true if (a) no provider configured (skip),
 *    (b) provider says success.
 *  → returns false if validation failed.
 */
class CaptchaVerifier
{
    private const ENDPOINTS = [
        'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha'     => 'https://hcaptcha.com/siteverify',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
    ];

    public function verifyForApp(DsrApp $app, ?string $token, ?string $ip = null): bool
    {
        return $this->verify($app->captcha_provider, $app->captcha_secret, $token, $ip, "dsr_app:{$app->id}");
    }

    public function verifyForCollection(\App\Models\ConsentCollectionPoint $cp, ?string $token, ?string $ip = null): bool
    {
        return $this->verify($cp->captcha_provider, $cp->captcha_secret, $token, $ip, "consent_cp:{$cp->id}");
    }

    /**
     * Generic verifier — pass provider + secret + token. Returns true if no
     * provider configured (skip), false on any failure.
     */
    public function verify(?string $provider, ?string $secret, ?string $token, ?string $ip = null, string $contextTag = ''): bool
    {
        if (!$provider || !$secret) return true;
        if (!$token) return false;

        $endpoint = self::ENDPOINTS[$provider] ?? null;
        if (!$endpoint) {
            Log::warning("CaptchaVerifier: unknown provider '{$provider}' for {$contextTag}");
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post($endpoint, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
            $body = $response->json() ?: [];
            $success = (bool) ($body['success'] ?? false);

            if (!$success) {
                Log::info('Captcha rejected', [
                    'provider' => $provider,
                    'context' => $contextTag,
                    'errors' => $body['error-codes'] ?? $body['errorCodes'] ?? null,
                ]);
            }

            // For reCAPTCHA v3, also enforce score threshold (≥0.5)
            if ($success && $provider === 'recaptcha_v3') {
                $score = (float) ($body['score'] ?? 0);
                if ($score < 0.5) {
                    Log::info('Captcha v3 score too low', ['score' => $score]);
                    return false;
                }
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('CaptchaVerifier exception: ' . $e->getMessage());
            // Fail-closed: when verification can't run, reject the request.
            return false;
        }
    }
}
