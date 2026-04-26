<?php

namespace App\Http\Middleware;

use App\Models\ConsentCollectionPoint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auth for Consent Partner API (server-to-server). Mirror DSR pattern.
 *
 * Required headers:
 *   X-Privasimu-Client-Key:  pk_consent_xxxxx
 *   X-Privasimu-Signature:   sha256=<hex of HMAC(rawBody, server_key)>
 *   X-Privasimu-Timestamp:   <epoch seconds>
 *
 * Attaches resolved ConsentCollectionPoint to $request->consentCollection.
 */
class AuthenticateConsentApiKey
{
    private const REPLAY_WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next)
    {
        $clientKey = $request->header('X-Privasimu-Client-Key');
        $signature = $request->header('X-Privasimu-Signature');
        $timestamp = $request->header('X-Privasimu-Timestamp');

        if (!$clientKey || !$signature || !$timestamp) {
            return response()->json([
                'error' => 'Missing required headers: X-Privasimu-Client-Key, X-Privasimu-Signature, X-Privasimu-Timestamp.',
            ], 401);
        }

        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return response()->json(['error' => 'Timestamp outside replay window.'], 401);
        }

        $cacheKey = 'consent:cp_by_client_key:' . sha1($clientKey);
        $cp = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($clientKey) {
            return ConsentCollectionPoint::where('client_key', $clientKey)->first();
        });

        if (!$cp) return response()->json(['error' => 'Invalid client_key.'], 401);
        if (!$cp->isApiKeyEnabled()) {
            return response()->json(['error' => 'API key auth not enabled for this collection.'], 403);
        }
        if (empty($cp->server_key)) {
            return response()->json(['error' => 'Collection has no server_key. Regenerate keys first.'], 500);
        }

        $rawBody = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $cp->server_key);
        if (!hash_equals($expected, $signature)) {
            Log::warning('Consent API key signature mismatch', [
                'client_key' => substr($clientKey, 0, 16) . '…',
                'cp_id' => $cp->id,
            ]);
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $request->merge(['_consent_cp' => $cp]);
        $request->consentCollection = $cp;
        return $next($request);
    }
}
