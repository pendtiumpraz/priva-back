<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Centralized factory for OUTBOUND HTTP requests, with TLS certificate
 * verification gated by DEPLOYMENT MODE (config `ai.deployment_mode`).
 *
 * Replaces the scattered `Http::...->withoutVerifying()` calls that silently
 * disabled TLS verification everywhere — a MITM risk, especially for the LLM
 * `/chat/completions` calls that carry prompt PII to external providers.
 *
 * Policy (product decision):
 *   - SaaS mode (default) → TLS is ALWAYS VERIFIED. Never skipped. Data leaving
 *     to external LLM / license / update servers over the public internet must
 *     not be interceptable/tamperable (man-in-the-middle).
 *   - On-prem mode        → verification MAY be skipped, because on-prem
 *     deployments typically run an INTERNAL / self-signed LLM inside a
 *     controlled network where a public CA certificate cannot exist.
 *
 * Usage (pass the target URL for readability/future host-aware logic):
 *     OutboundHttp::client($baseUrl)
 *         ->timeout(60)
 *         ->withHeaders($headers)
 *         ->post($baseUrl.'/chat/completions', $payload);
 */
class OutboundHttp
{
    public static function client(?string $url = null): PendingRequest
    {
        // On-prem: allow skipping TLS verification (internal/self-signed LLM).
        // SaaS: keep TLS verification at its secure default — never skip.
        if (self::isOnPrem()) {
            return Http::withoutVerifying();
        }

        // Fresh PendingRequest with TLS verification left ON (secure default).
        return Http::withOptions([]);
    }

    /**
     * Is the platform running in on-prem deployment mode?
     * (config `ai.deployment_mode` === 'onprem'; default 'saas').
     */
    public static function isOnPrem(): bool
    {
        return config('ai.deployment_mode', 'saas') === 'onprem';
    }
}
