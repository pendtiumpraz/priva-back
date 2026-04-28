<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\CookieLog;
use App\Services\Consent\IpGeoResolver;
use App\Services\Consent\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cookie banner capture (anonymous, kind=cookie_banner).
 *
 * Endpoints:
 *   POST /api/v2/cookies/capture   — record visitor cookie choice
 *   GET  /api/v2/cookies/state     — return current preferences for visitor_id
 *   POST /api/v2/cookies/withdraw  — visitor revokes optional categories
 *
 * No auth — public. Rate-limited per visitor_id + IP.
 * Captures auto-parsed UA + geo (best-effort).
 */
class CookieCaptureController extends Controller
{
    public function capture(Request $request)
    {
        $data = $request->validate([
            'collection_id' => 'required|string|max:200',
            'visitor_id' => 'required|string|min:8|max:80',
            'session_id' => 'nullable|string|max:80',
            'choices' => 'required|array',
            'choices.necessary' => 'sometimes|boolean',
            'choices.analytics' => 'sometimes|boolean',
            'choices.marketing' => 'sometimes|boolean',
            'choices.preferences' => 'sometimes|boolean',
            'policy_version' => 'nullable|string|max:32',
            'page_url' => 'nullable|string|max:500',
            'referrer' => 'nullable|string|max:500',
        ]);

        // Rate limit: 60/min per visitor (sufficient for re-renders), 200/min per IP
        $visitorKey = 'cookie-capture:visitor:'.$data['visitor_id'];
        if (RateLimiter::tooManyAttempts($visitorKey, 60)) {
            return response()->json(['error' => 'Too many capture attempts for this visitor.'], 429);
        }
        $ipKey = 'cookie-capture:ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 200)) {
            return response()->json(['error' => 'Too many capture attempts.'], 429);
        }

        $collection = $this->resolveCollection($data['collection_id']);
        if (! $collection) {
            return response()->json(['error' => 'Collection not found.'], 404);
        }
        if ($collection->kind !== ConsentCollectionPoint::KIND_COOKIE) {
            return response()->json([
                'error' => 'Wrong collection kind. Cookie banner endpoint requires kind=cookie_banner.',
            ], 422);
        }

        // Force necessary=true (cannot be opted out)
        $choices = array_merge($data['choices'] ?? [], ['necessary' => true]);

        $ua = UserAgentParser::parse($request->userAgent());
        $geo = IpGeoResolver::resolve($request->ip());

        $log = CookieLog::create([
            'org_id' => $collection->org_id,
            'collection_id' => $collection->id,
            'visitor_id' => $data['visitor_id'],
            'session_id' => $data['session_id'] ?? null,
            'ip_address' => $request->ip(),
            'ip_country' => $geo['country'],
            'ip_city' => $geo['city'],
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'browser_name' => $ua['browser_name'],
            'browser_version' => $ua['browser_version'],
            'os_name' => $ua['os_name'],
            'device_type' => $ua['device_type'],
            'page_url' => $data['page_url'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'choices' => $choices,
            'policy_version' => $data['policy_version'] ?? '1.0',
            'captured_at' => now(),
            'expires_at' => now()->addDays((int) config('privasimu.cookie_log_retention_days', 90)),
        ]);

        RateLimiter::hit($visitorKey, 60);
        RateLimiter::hit($ipKey, 60);

        return response()->json([
            'ok' => true,
            'log_id' => $log->id,
            'expires_at' => $log->expires_at,
        ], 201);
    }

    public function state(Request $request)
    {
        $data = $request->validate([
            'collection_id' => 'required|string|max:200',
            'visitor_id' => 'required|string|min:8|max:80',
        ]);

        $collection = $this->resolveCollection($data['collection_id']);
        if (! $collection || $collection->kind !== ConsentCollectionPoint::KIND_COOKIE) {
            return response()->json(['error' => 'Collection not found.'], 404);
        }

        $latest = CookieLog::query()
            ->where('org_id', $collection->org_id)
            ->where('collection_id', $collection->id)
            ->where('visitor_id', $data['visitor_id'])
            ->orderByDesc('captured_at')
            ->first();

        if (! $latest) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'visitor_id' => $latest->visitor_id,
                'choices' => $latest->choices,
                'captured_at' => $latest->captured_at,
                'policy_version' => $latest->policy_version,
            ],
        ]);
    }

    public function withdraw(Request $request)
    {
        // Withdraw = capture with all-false choices (necessary stays true)
        $request->merge([
            'choices' => array_merge((array) $request->input('choices', []), [
                'analytics' => false,
                'marketing' => false,
                'preferences' => false,
                'necessary' => true,
            ]),
        ]);
        return $this->capture($request);
    }

    private function resolveCollection(string $key): ?ConsentCollectionPoint
    {
        $cacheKey = 'cookie:collection:'.sha1($key);
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($key) {
            return ConsentCollectionPoint::query()
                ->where('embed_token', $key)
                ->orWhere('collection_id', $key)
                ->orWhere('id', $key)
                ->first();
        });
    }
}
