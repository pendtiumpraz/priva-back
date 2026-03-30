<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use Illuminate\Http\Request;

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
     * Public API to get consent configuration (banner settings and items)
     */
    public function config(Request $request)
    {
        $request->validate([
            'collection_id' => 'required|string',
        ]);

        $collection = ConsentCollectionPoint::with(['items' => function ($query) {
            $query->where('is_active', true);
        }])->where('collection_id', $request->collection_id)
          ->orWhere('id', $request->collection_id)
          ->firstOrFail();

        return response()->json([
            'data' => [
                'collection' => [
                    'name' => $collection->name,
                    'domain' => $collection->domain,
                    'settings' => $collection->settings,
                ],
                'items' => $collection->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'full_text' => $item->full_text,
                        'version' => $item->version,
                        'is_required' => $item->is_required,
                    ];
                }),
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

        $collection = ConsentCollectionPoint::where('collection_id', $request->collection_id)
            ->orWhere('id', $request->collection_id)
            ->firstOrFail();

        $log = ConsentLog::create([
            'org_id' => $collection->org_id,
            'collection_id' => $collection->id,
            'user_identifier' => $request->user_identifier,
            'consented_items' => $request->consented_items,
            'policy_version' => $request->policy_version ?? '1.0',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Increment the records count on the collection point automatically
        $collection->increment('records_count');

        // Fire webhook if configured
        if ($collection->webhook_url) {
            try {
                \Http::timeout(5)->post($collection->webhook_url, [
                    'event' => 'consent.captured',
                    'collection_id' => $collection->collection_id,
                    'user_identifier' => $log->user_identifier,
                    'consented_items' => $log->consented_items,
                    'policy_version' => $log->policy_version,
                    'ip_address' => $log->ip_address,
                    'timestamp' => $log->created_at,
                ]);
            } catch (\Throwable $e) {
                // Non-blocking — log but don't fail the request
                \Log::warning("Webhook fire failed for collection {$collection->id}: " . $e->getMessage());
            }
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
