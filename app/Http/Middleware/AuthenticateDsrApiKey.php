<?php

namespace App\Http\Middleware;

use App\Models\DsrApp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auth for DSR Partner API (server-to-server).
 *
 * Required headers:
 *   X-Privasimu-Client-Key:  pk_live_xxxxx
 *   X-Privasimu-Signature:   sha256=<hex of HMAC(rawBody, server_key)>
 *   X-Privasimu-Timestamp:   <epoch seconds, used to reject replays >5min old>
 *
 * On success, attaches the DsrApp to the request as $request->dsrApp.
 *
 * Differences from embed widget auth (DsrPublicController):
 *   - No origin check (server-to-server, no Origin header)
 *   - No captcha (klien sudah trusted)
 *   - HMAC signature mandatory (proves possession of server_key)
 *   - Replay protection via timestamp
 */
class AuthenticateDsrApiKey
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

        // Replay protection
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::REPLAY_WINDOW_SECONDS) {
            return response()->json([
                'error' => 'Timestamp outside replay window (' . self::REPLAY_WINDOW_SECONDS . 's). Server time: ' . time(),
            ], 401);
        }

        // Resolve app by client_key (cached 10m)
        $cacheKey = 'dsr_app_by_client_key:' . sha1($clientKey);
        $app = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($clientKey) {
            return DsrApp::where('client_key', $clientKey)->first();
        });

        if (!$app) {
            return response()->json(['error' => 'Invalid client_key.'], 401);
        }

        if (!$app->is_active || !$app->isApiKeyEnabled()) {
            return response()->json(['error' => 'API key auth not enabled for this app, or app inactive.'], 403);
        }

        if (empty($app->server_key)) {
            return response()->json(['error' => 'App has no server_key configured. Regenerate keys first.'], 500);
        }

        // Verify HMAC signature against raw body
        $rawBody = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $app->server_key);

        if (!hash_equals($expected, $signature)) {
            Log::warning('DSR API key signature mismatch', [
                'client_key' => substr($clientKey, 0, 16) . '…',
                'app_id' => $app->id,
            ]);
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        // Attach for downstream controller
        $request->merge(['_dsr_app' => $app]);
        $request->dsrApp = $app;

        return $next($request);
    }
}
