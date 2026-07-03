<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Centralized factory for OUTBOUND HTTP requests, with TLS certificate
 * verification decided by DEPLOYMENT MODE (config `ai.deployment_mode`) + the
 * target host.
 *
 * Replaces the scattered `Http::...->withoutVerifying()` calls that silently
 * disabled TLS verification everywhere — a MITM risk, especially for the LLM
 * `/chat/completions` calls that carry prompt PII to external providers.
 *
 * Policy (product decision — HYBRID):
 *   - SaaS mode (default) → TLS is ALWAYS VERIFIED. Never skipped.
 *   - On-prem mode → verification is skipped ONLY for INTERNAL/PRIVATE hosts
 *     (localhost / RFC1918 / reserved IP), i.e. a self-signed LLM inside the
 *     controlled network. Any host reachable over the PUBLIC internet — an
 *     external LLM (OpenRouter/DeepSeek/…), the license server, the update
 *     server — is STILL VERIFIED even on-prem. So "on-prem tapi AI-nya keluar
 *     ke internet" → tetap diverifikasi.
 *
 * Pass the actual target URL so the private-host detection can work:
 *
 *     OutboundHttp::client($baseUrl)
 *         ->timeout(60)
 *         ->withHeaders($headers)
 *         ->post($baseUrl.'/chat/completions', $payload);
 */
class OutboundHttp
{
    public static function client(?string $url = null): PendingRequest
    {
        if (self::shouldSkipVerification($url)) {
            return Http::withoutVerifying();
        }

        // Fresh PendingRequest with TLS verification left ON (secure default).
        return Http::withOptions([]);
    }

    /**
     * Skip TLS verification only when on-prem AND the target is an internal /
     * private host. SaaS never skips; on-prem still verifies public endpoints.
     */
    private static function shouldSkipVerification(?string $url): bool
    {
        // SaaS: ALWAYS verify — data to external LLM/license/update over the
        // public internet must not be MITM-able.
        if (! self::isOnPrem()) {
            return false;
        }

        // On-prem: skip ONLY for a local/private host (self-signed internal LLM).
        // Unknown host ($url null) → cannot prove it's internal → verify (safe).
        return $url !== null && self::isPrivateHost($url);
    }

    /**
     * Is the platform running in on-prem deployment mode?
     * (config `ai.deployment_mode` === 'onprem'; default 'saas').
     */
    public static function isOnPrem(): bool
    {
        return config('ai.deployment_mode', 'saas') === 'onprem';
    }

    /**
     * Is the URL's host localhost or a private/reserved IP range? These are the
     * hosts where a public CA certificate cannot exist (on-prem/local
     * self-signed LLM). Mirrors the ranges used by App\Services\OutboundUrlValidator.
     */
    public static function isPrivateHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]')); // strip IPv6 literal brackets

        // Hostname-based local names.
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return true;
        }

        // IP literal — private (RFC1918) + reserved ranges (loopback, link-local).
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($host === '::1' || $host === '::') {
                return true;
            }

            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            return $isPublic === false;
        }

        return false;
    }
}
