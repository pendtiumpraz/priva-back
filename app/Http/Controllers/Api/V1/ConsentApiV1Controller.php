<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentLog;
use App\Services\Consent\ConsentOutboundGate;
use Illuminate\Http\Request;

/**
 * Consent Partner API v1 — server-to-server (alternative to embed widget).
 *
 * Auth: middleware `consent.api_key` (HMAC-SHA256 signed body).
 *
 * Endpoints:
 *   POST /api/v1/consent/capture        — record consent decision
 *   GET  /api/v1/consent/state          — query latest consent state for user
 *   GET  /api/v1/consent/items          — list active consent items + categories
 */
class ConsentApiV1Controller extends Controller
{
    public function capture(Request $request)
    {
        $cp = $request->consentCollection;
        if (!$cp) return response()->json(['error' => 'Collection not resolved'], 500);

        $data = $request->validate([
            'user_identifier' => 'required|string|max:200',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string|max:32',
            'channel' => 'nullable|string|max:64', // klien's source: 'web' | 'mobile' | 'cs_form' | etc
        ]);

        $log = ConsentLog::create([
            'org_id' => $cp->org_id,
            'collection_id' => $cp->id,
            'user_identifier' => $data['user_identifier'],
            'consented_items' => $data['consented_items'],
            'policy_version' => $data['policy_version'] ?? '1.0',
            'ip_address' => $request->ip(),
            'user_agent' => 'partner_api:' . ($data['channel'] ?? 'unknown'),
        ]);

        // Gerbang yang sama dengan jalur widget. Pintu masuk yang berbeda tidak
        // boleh menghasilkan penjagaan yang berbeda — kalau partner API lolos
        // sementara widget dijaga, aturannya hanya menyulitkan tenant yang
        // jujur.
        $gate = app(ConsentOutboundGate::class)->decide(
            $cp,
            (string) $log->user_identifier,
            'partner_api'
        );

        if ($gate === null || ! $gate->blocked) {
            if ($cp->webhook_url) {
                FireConsentWebhookJob::dispatch(
                    $cp->webhook_url,
                    $cp->collection_id,
                    array_merge([
                        'event' => 'consent.captured',
                        'source' => 'partner_api',
                        'collection_id' => $cp->collection_id,
                        'user_identifier' => $log->user_identifier,
                        'consented_items' => $log->consented_items,
                        'policy_version' => $log->policy_version,
                        'timestamp' => $log->created_at,
                    ], $gate ? ['decision' => ConsentOutboundGate::payload($gate)] : [])
                );
            }

            $org = \App\Models\Organization::find($cp->org_id);
            $crms = $org?->settings['crm_connections'] ?? [];
            foreach ($crms as $providerId => $config) {
                PushConsentToCrmJob::dispatch($providerId, (array) $config, $log->id);
            }
        }

        return response()->json([
            'message' => 'Consent captured.',
            'log_id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
        ], 201);
    }

    public function state(Request $request)
    {
        $cp = $request->consentCollection;
        $data = $request->validate(['user_identifier' => 'required|string|max:200']);

        $latest = ConsentLog::where('collection_id', $cp->id)
            ->where('user_identifier', $data['user_identifier'])
            ->latest()->first();

        return response()->json([
            'has_record' => (bool) $latest,
            'consented_items' => $latest?->consented_items ?? [],
            'policy_version' => $latest?->policy_version,
            'last_updated' => $latest?->created_at?->toIso8601String(),
        ]);
    }

    public function items(Request $request)
    {
        $cp = $request->consentCollection;
        $items = $cp->items()->where('is_active', true)
            ->orderBy('category')->orderBy('title')
            ->get(['id', 'title', 'description', 'category', 'cookie_keys', 'version', 'is_required']);

        return response()->json([
            'collection' => [
                'name' => $cp->name,
                'collection_id' => $cp->collection_id,
                'display_mode' => $cp->display_mode,
                'audience' => $cp->audience,
            ],
            'items' => $items,
        ]);
    }
}
