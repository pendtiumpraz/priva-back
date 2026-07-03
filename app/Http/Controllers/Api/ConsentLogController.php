<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\Organization;
use App\Services\CaptchaVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ConsentLogController extends Controller
{
    public function __construct(private ?CaptchaVerifier $captcha = null)
    {
        $this->captcha = $captcha ?: app(CaptchaVerifier::class);
    }

    /**
     * Get list of collected consent logs (Protected)
     */
    public function index(Request $request)
    {
        $query = ConsentLog::query();

        // Superadmin bypass / org scope
        if ($request->user()->role !== 'superadmin') {
            $query->where('org_id', $request->user()->org_id);
        } elseif ($request->filled('org_id')) {
            $query->where('org_id', $request->org_id);
        }

        if ($request->filled('collection_id')) {
            $query->where('collection_id', $request->collection_id);
        }

        // Filter by user_identifier (for User Profile page)
        if ($request->filled('user_identifier')) {
            $query->where('user_identifier', $request->user_identifier);
        }

        // Phase B filters for the new identifiable schema:
        if ($request->filled('email')) {
            $query->where('email', strtolower(trim($request->email)));
        }
        if ($request->filled('source_form')) {
            $query->where('source_form', $request->source_form);
        }
        if ($request->filled('country')) {
            $query->where('ip_country', strtoupper($request->country));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }
        // purpose_keys may arrive as comma-separated string OR array (from FE)
        $purposes = $request->input('purpose_keys');
        if ($purposes) {
            $list = is_array($purposes) ? $purposes : explode(',', (string) $purposes);
            foreach (array_filter($list) as $p) {
                $query->where('purpose_keys', 'like', '%"'.addslashes(trim($p)).'"%');
            }
        }

        return response()->json([
            'data' => $query->orderBy('created_at', 'desc')->with('collectionPoint')->take(1000)->get(),
        ]);
    }

    /**
     * Public API to get consent configuration (banner settings and items).
     * Cached aggressively (5 min) because this endpoint is hit on EVERY
     * page load of every tenant's public site. Cache is keyed by the
     * collection_id the caller sent, which is either the human-readable
     * code or the UUID — both keys map to the same payload so invalidation
     * must clear both variants.
     *
     * Invalidation: see ConsentCollectionPoint::bustConsentCache() and the
     * observer hooks on ConsentItem writes.
     */
    public function config(Request $request)
    {
        $request->validate([
            'collection_id' => 'required|string',
            // category_filter=cookie → only essential/analytics/marketing/functional (anonymous)
            // category_filter=app    → only NON-cookie items (consent for register/checkout)
            // category_filter=all (default) → semua items active
            'category_filter' => 'nullable|in:cookie,app,all',
        ]);

        $filter = $request->input('category_filter', 'all');
        $key = 'consent:config:'.sha1($request->collection_id.'|'.$filter);

        $payload = Cache::remember($key, now()->addMinutes(5), function () use ($request, $filter) {
            // Resolve by embed_token (preferred) ATAU legacy collection_id ATAU UUID
            $collection = ConsentCollectionPoint::with(['items' => function ($query) use ($filter) {
                $query->where('is_active', true);
                if ($filter === 'cookie') {
                    $query->whereIn('category', ConsentItem::COOKIE_CATEGORIES);
                } elseif ($filter === 'app') {
                    // Consent items = anything that is NOT a cookie category.
                    // E.g., 'newsletter', 'whatsapp', 'profiling', 'sharing_3p', custom.
                    $query->whereNotIn('category', ConsentItem::COOKIE_CATEGORIES);
                }
                $query->orderByRaw("CASE WHEN category='essential' THEN 0 ELSE 1 END")
                    ->orderBy('title');
            }])
                ->where(function ($q) use ($request) {
                    $q->where('embed_token', $request->collection_id)
                        ->orWhere('collection_id', $request->collection_id)
                        ->orWhere('id', $request->collection_id);
                })
                ->firstOrFail();

            return [
                'collection' => [
                    'name' => $collection->name,
                    'domain' => $collection->domain,
                    'settings' => $collection->settings,
                    'display_mode' => $collection->display_mode ?: 'banner_bottom',
                    'display_frequency' => $collection->display_frequency ?: 'once',
                    'audience' => $collection->audience ?: 'anonymous_only',
                    'locale' => $collection->locale ?: 'id',
                ],
                'captcha' => $collection->captcha_provider ? [
                    'provider' => $collection->captcha_provider,
                    'site_key' => $collection->captcha_site_key,
                ] : null,
                'items' => $collection->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'full_text' => $item->full_text,
                    'category' => $item->category ?: 'essential',
                    'cookie_keys' => $item->cookie_keys ?? [],
                    'version' => $item->version,
                    'is_required' => $item->is_required,
                ])->all(),
            ];
        });

        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }

    /**
     * Public API to get the latest consent state of a user
     */
    public function state(Request $request)
    {
        $request->validate([
            'collection_id' => 'required|string',
            'user_identifier' => 'required|string',
        ]);

        $collection = ConsentCollectionPoint::where('collection_id', $request->collection_id)
            ->orWhere('id', $request->collection_id)
            ->firstOrFail();

        $latestLog = ConsentLog::where('collection_id', $collection->id)
            ->where('user_identifier', $request->user_identifier)
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'has_record' => $latestLog ? true : false,
                'consented_items' => $latestLog ? $latestLog->consented_items : [],
                'last_updated' => $latestLog ? $latestLog->created_at : null,
            ],
        ]);
    }

    /**
     * Public API to capture user consent from external websites
     */
    public function capture(Request $request)
    {
        $request->validate([
            'collection_id' => 'required|string',
            'user_identifier' => 'required|string|max:200',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string|max:32',
            'captcha_token' => 'nullable|string|max:4000',
        ]);

        // Rate limit per IP — generous since legitimate widgets fire 1x per session.
        $rateKey = 'consent-capture:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return response()->json(['error' => 'Too many requests. Try again later.'], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // Resolve via embed_token / collection_id / UUID (cached)
        $cacheKey = 'consent:collection:'.sha1($request->collection_id);
        $collection = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($request) {
            return ConsentCollectionPoint::where('embed_token', $request->collection_id)
                ->orWhere('collection_id', $request->collection_id)
                ->orWhere('id', $request->collection_id)
                ->firstOrFail();
        });

        // Captcha verify (no-op if provider not configured)
        if ($collection->captcha_provider) {
            if (! $this->captcha->verifyForCollection($collection, $request->input('captcha_token'), $request->ip())) {
                return response()->json(['error' => 'Verifikasi captcha gagal.'], 422);
            }
        }

        // Phase B: capture extended identifiable fields when present.
        // Backwards-compat: legacy clients still send only user_identifier.
        // For app_consent kind, email is recommended (FE will require).
        $email = $request->input('email');
        $isEmailIdentifier = filter_var($request->user_identifier, FILTER_VALIDATE_EMAIL);
        if (! $email && $isEmailIdentifier) {
            $email = strtolower(trim($request->user_identifier));
        }

        // Denormalize purpose_keys from consented_items (keys where value=true)
        $consented = $request->consented_items ?? [];
        $purposeKeys = [];
        foreach ($consented as $k => $v) {
            if ($v === true || $v === 'true' || $v === 1) {
                $purposeKeys[] = is_string($k) ? $k : (string) $k;
            }
        }

        $ua = \App\Services\Consent\UserAgentParser::parse($request->userAgent());
        $geo = \App\Services\Consent\IpGeoResolver::resolve($request->ip());

        $log = ConsentLog::create([
            'org_id' => $collection->org_id,
            'collection_id' => $collection->id,
            'user_identifier' => $request->user_identifier,
            'email' => $email ? strtolower($email) : null,
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'external_user_ref' => $request->input('external_user_ref'),
            'consented_items' => $consented,
            'purpose_keys' => $purposeKeys,
            'policy_version' => $request->policy_version ?? '1.0',
            'source_form' => $request->input('source_form'),
            'ip_address' => $request->ip(),
            'ip_country' => $geo['country'],
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'browser_name' => $ua['browser_name'],
            'browser_version' => $ua['browser_version'],
            'os_name' => $ua['os_name'],
            'device_type' => $ua['device_type'],
        ]);

        // records_count moved out of the hot path — scheduled command
        // `consent:recount` (default every 5 min) recomputes from consent_logs.
        // The old `$collection->increment()` serialized writes + caused
        // MySQL/PG row-lock contention at high concurrency.

        // Webhook is async via queue so the response returns in <50ms even if
        // the tenant's receiver is slow / down / rate-limited. 3 retries with
        // exponential backoff handled by FireConsentWebhookJob.
        if ($collection->webhook_url) {
            FireConsentWebhookJob::dispatch(
                $collection->webhook_url,
                $collection->collection_id,
                [
                    'event' => 'consent.captured',
                    'collection_id' => $collection->collection_id,
                    'user_identifier' => $log->user_identifier,
                    'consented_items' => $log->consented_items,
                    'policy_version' => $log->policy_version,
                    'ip_address' => $log->ip_address,
                    'timestamp' => $log->created_at,
                ]
            );
        }

        // CRM push also async — a Salesforce/HubSpot round-trip can be
        // several seconds, we don't block the user for it.
        $org = Organization::find($collection->org_id);
        $crms = $org->settings['crm_connections'] ?? [];
        foreach ($crms as $providerId => $config) {
            PushConsentToCrmJob::dispatch($providerId, (array) $config, $log->id);
        }

        // CORS: capture adalah endpoint PUBLIK untuk widget di situs eksternal
        // (samakan dgn config() yang sudah `*`). Sebelumnya header ini absen →
        // POST browser lintas-origin bisa ditolak. Preflight OPTIONS untuk
        // origin arbitrer tetap diatur HandleCors (allowed_origins); untuk
        // widget yang di-iframe dari origin Privasimu (ter-whitelist) sudah
        // lolos. Response ini eksplisit `*` supaya konsisten dengan config().
        return response()->json([
            'message' => 'Consent captured successfully',
            'log_id' => $log->id,
        ], 201)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS');
    }

    /**
     * Save webhook URL for a collection point (Protected)
     */
    public function saveWebhook(Request $request, string $id)
    {
        $request->validate(['webhook_url' => 'nullable|url|max:500']);

        $collection = ConsentCollectionPoint::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        $collection->update(['webhook_url' => $request->webhook_url]);

        return response()->json(['message' => 'Webhook URL saved', 'webhook_url' => $collection->webhook_url]);
    }
}
