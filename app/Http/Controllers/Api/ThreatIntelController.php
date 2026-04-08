<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives external threat intelligence alerts (SOCRadar, etc.)
 * and auto-creates breach incidents.
 */
class ThreatIntelController extends Controller
{
    /**
     * POST /api/webhooks/threat-intel/{org_id}
     * 
     * Receives alerts from external threat intel providers.
     * Authenticated via X-Webhook-Secret header matching the org's configured secret.
     */
    public function receive(string $orgId, Request $request)
    {
        // Verify organization exists
        $org = Organization::find($orgId);
        if (!$org) {
            return response()->json(['error' => 'Organization not found'], 404);
        }

        // Verify webhook secret
        $secret = $request->header('X-Webhook-Secret');
        $webhook = Webhook::where('org_id', $orgId)
            ->where('is_active', true)
            ->whereJsonContains('events', 'threat_intel.inbound')
            ->first();

        if (!$webhook || !$secret || !hash_equals($webhook->secret, $secret)) {
            Log::warning("Threat intel webhook auth failed for org {$orgId}", [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Parse the incoming alert — support multiple formats
        $payload = $request->all();
        $source = $request->header('X-Provider', $payload['provider'] ?? 'Unknown Provider');

        // Normalize to our breach format
        $alert = $this->normalizeAlert($payload, $source);

        if (!$alert['title']) {
            return response()->json(['error' => 'Alert title is required'], 422);
        }

        // Auto-create breach incident
        $count = BreachIncident::where('org_id', $orgId)->count() + 1;
        $code = 'TI-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $breach = BreachIncident::create([
            'org_id' => $orgId,
            'incident_code' => $code,
            'title' => $alert['title'],
            'description' => $alert['description'],
            'severity' => $alert['severity'],
            'source' => "Threat Intel: {$source}",
            'status' => 'open',
            'is_simulation' => false,
            'affected_data_types' => $alert['affected_data_types'],
            'affected_subjects_count' => $alert['affected_count'],
            'root_cause' => $alert['root_cause'],
            'detected_at' => $alert['detected_at'] ?? now(),
            'detected_by' => "Auto-detect ({$source})",
            'notification_required' => in_array($alert['severity'], ['high', 'critical']),
            'notification_deadline' => in_array($alert['severity'], ['high', 'critical']) ? now()->addHours(72) : null,
            'timeline_log' => [
                [
                    'event' => "Alert diterima dari {$source} via Threat Intel Webhook",
                    'at' => now()->toISOString(),
                    'by' => 'System (Auto)',
                    'metadata' => [
                        'provider' => $source,
                        'alert_id' => $payload['alert_id'] ?? $payload['id'] ?? null,
                        'ip' => $request->ip(),
                    ],
                ],
            ],
        ]);

        // Update webhook stats
        $webhook->increment('total_deliveries');
        $webhook->update(['last_triggered_at' => now()]);

        Log::info("Threat intel alert created breach {$code} for org {$orgId} from {$source}");

        return response()->json([
            'message' => 'Alert diterima dan breach incident dibuat.',
            'data' => [
                'incident_code' => $code,
                'breach_id' => $breach->id,
                'severity' => $alert['severity'],
            ],
        ], 201);
    }

    /**
     * Normalize different provider formats into our standard alert format.
     * Supports: SOCRadar, generic, and custom formats.
     */
    private function normalizeAlert(array $payload, string $source): array
    {
        // SOCRadar format
        if (isset($payload['alarm_id']) || str_contains(strtolower($source), 'socradar')) {
            return [
                'title' => $payload['alarm_title'] ?? $payload['title'] ?? 'SOCRadar Alert',
                'description' => $this->buildDescription($payload, [
                    'alarm_details', 'description', 'details', 'content',
                ]),
                'severity' => $this->mapSeverity($payload['severity'] ?? $payload['risk_level'] ?? 'medium'),
                'affected_data_types' => $payload['affected_assets'] ?? $payload['data_types'] ?? [],
                'affected_count' => $payload['affected_count'] ?? $payload['exposure_count'] ?? null,
                'root_cause' => $payload['source_type'] ?? $payload['threat_type'] ?? null,
                'detected_at' => $payload['detected_at'] ?? $payload['alarm_date'] ?? $payload['created_at'] ?? null,
            ];
        }

        // Generic / Custom format
        return [
            'title' => $payload['title'] ?? $payload['name'] ?? $payload['subject'] ?? 'External Threat Alert',
            'description' => $this->buildDescription($payload, [
                'description', 'details', 'message', 'body', 'content', 'summary',
            ]),
            'severity' => $this->mapSeverity($payload['severity'] ?? $payload['priority'] ?? $payload['risk'] ?? 'medium'),
            'affected_data_types' => $payload['affected_data_types'] ?? $payload['data_types'] ?? $payload['assets'] ?? [],
            'affected_count' => $payload['affected_count'] ?? $payload['records_count'] ?? null,
            'root_cause' => $payload['root_cause'] ?? $payload['category'] ?? $payload['type'] ?? null,
            'detected_at' => $payload['detected_at'] ?? $payload['timestamp'] ?? $payload['date'] ?? null,
        ];
    }

    /**
     * Try multiple description fields and combine them.
     */
    private function buildDescription(array $payload, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key])) {
                $parts[] = trim($payload[$key]);
            }
        }
        return implode("\n\n", $parts) ?: 'Alert diterima dari external threat intelligence provider.';
    }

    /**
     * Map various severity formats to our standard.
     */
    private function mapSeverity(string $input): string
    {
        $input = strtolower(trim($input));
        $map = [
            'critical' => 'critical', 'very high' => 'critical', '5' => 'critical', 'p1' => 'critical',
            'high' => 'high', '4' => 'high', 'p2' => 'high', 'important' => 'high',
            'medium' => 'medium', 'moderate' => 'medium', '3' => 'medium', 'p3' => 'medium',
            'low' => 'low', 'minor' => 'low', 'info' => 'low', '2' => 'low', '1' => 'low', 'p4' => 'low', 'p5' => 'low',
        ];
        return $map[$input] ?? 'medium';
    }
}
