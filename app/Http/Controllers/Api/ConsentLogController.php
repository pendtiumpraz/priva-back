<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConsentLogController extends Controller
{
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

        return response()->json([
            'data' => $query->orderBy('created_at', 'desc')->with('collectionPoint')->take(1000)->get()
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
        ]);

        $key = 'consent:config:' . sha1($request->collection_id);
        $payload = Cache::remember($key, now()->addMinutes(5), function () use ($request) {
            $collection = ConsentCollectionPoint::with(['items' => function ($query) {
                $query->where('is_active', true);
            }])->where('collection_id', $request->collection_id)
              ->orWhere('id', $request->collection_id)
              ->firstOrFail();

            return [
                'collection' => [
                    'name' => $collection->name,
                    'domain' => $collection->domain,
                    'settings' => $collection->settings,
                ],
                'items' => $collection->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'full_text' => $item->full_text,
                    'version' => $item->version,
                    'is_required' => $item->is_required,
                ])->all(),
            ];
        });

        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
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
            ]
        ]);
    }

    /**
     * Public API to capture user consent from external websites
     */
    public function capture(Request $request)
    {
        $request->validate([
            'collection_id' => 'required|string',
            'user_identifier' => 'required|string',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string',
        ]);

        // Collection lookup cached 10 min so the hot path does ONE cache read
        // instead of a DB query per capture. Misses fall through to Postgres.
        $cacheKey = 'consent:collection:' . sha1($request->collection_id);
        $collection = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($request) {
            return ConsentCollectionPoint::where('collection_id', $request->collection_id)
                ->orWhere('id', $request->collection_id)
                ->firstOrFail();
        });

        $log = ConsentLog::create([
            'org_id' => $collection->org_id,
            'collection_id' => $collection->id,
            'user_identifier' => $request->user_identifier,
            'consented_items' => $request->consented_items,
            'policy_version' => $request->policy_version ?? '1.0',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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
        $org = \App\Models\Organization::find($collection->org_id);
        $crms = $org->settings['crm_connections'] ?? [];
        foreach ($crms as $providerId => $config) {
            PushConsentToCrmJob::dispatch($providerId, (array) $config, $log->id);
        }

        return response()->json([
            'message' => 'Consent captured successfully',
            'log_id' => $log->id,
        ], 201);
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
