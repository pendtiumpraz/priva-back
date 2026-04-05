<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    /**
     * Get integration settings for current organization
     */
    public function getSettings(Request $request)
    {
        $org = $request->user()->organization;
        $settings = $org->settings ?? [];
        return response()->json([
            'data' => [
                'telegram_bot_token' => $settings['telegram_bot_token'] ?? '',
                'telegram_chat_id' => $settings['telegram_chat_id'] ?? '',
                'siem_webhook_url' => $settings['siem_webhook_url'] ?? '',
                'soar_webhook_url' => $settings['soar_webhook_url'] ?? '',
            ]
        ]);
    }

    /**
     * Update integration settings for current organization
     */
    public function updateSettings(Request $request)
    {
        $org = $request->user()->organization;
        $settings = $org->settings ?? [];
        
        if ($request->has('telegram_bot_token')) $settings['telegram_bot_token'] = $request->telegram_bot_token;
        if ($request->has('telegram_chat_id')) $settings['telegram_chat_id'] = $request->telegram_chat_id;
        if ($request->has('siem_webhook_url')) $settings['siem_webhook_url'] = $request->siem_webhook_url;
        if ($request->has('soar_webhook_url')) $settings['soar_webhook_url'] = $request->soar_webhook_url;

        $org->update(['settings' => $settings]);
        
        return response()->json(['success' => true, 'message' => 'Integration settings saved.']);
    }

    /**
     * Send breach incident detail to Telegram 
     */
    public function syncBreachTelegram(Request $request, $id)
    {
        try {
            // Find the breach record
            $breach = DB::table('breach_incidents')->where('id', $id)->first();
            if (!$breach) {
                return response()->json(['error' => 'Breach record not found'], 404);
            }

            // Fetch from tenant level settings
            $org = $request->user()->organization;
            $settings = $org->settings ?? [];
            $token = $settings['telegram_bot_token'] ?? null;
            $chatId = $settings['telegram_chat_id'] ?? null;

            if (empty($token) || empty($chatId)) {
                return response()->json([
                    'success' => false,
                    'is_missing_config' => true,
                    'message' => 'Telegram Bot Token & Chat ID belum dikonfigurasi. Silakan atur di integrasi terlebih dahulu.',
                ], 400);
            }

            // Format message
            $message = "🚨 *INCIDENT ALERT: " . mb_strtoupper((string) $breach->severity) . "* 🚨\n\n";
            $message .= "*Incident Code:* " . $breach->incident_code . "\n";
            $message .= "*Title:* " . $breach->title . "\n";
            $message .= "*Status:* " . mb_strtoupper((string) $breach->status) . "\n";
            $message .= "*Detected At:* " . $breach->detected_at . "\n\n";
            $message .= "*Description:*\n" . $breach->description . "\n\n";
            $message .= "🔒 _Please check the Privasimu Dashboard for more details._";

            // Send to Telegram
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);

            if ($response->successful()) {
                Log::info("Telegram War Room Sync Success for Breach {$id}");
                return response()->json([
                    'success' => true,
                    'message' => 'Message successfully sent to Telegram War Room.',
                    'response' => $response->json()
                ]);
            }

            throw new \Exception($response->body());

        } catch (\Exception $e) {
            Log::error("Telegram Integration Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed to sync to Telegram: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Broadcast Breach to SIEM/SOAR Webhook
     */
    public function syncBreachSiem(Request $request, $id)
    {
        try {
            $breach = DB::table('breach_incidents')->where('id', $id)->first();
            if (!$breach) {
                return response()->json(['error' => 'Breach record not found'], 404);
            }

            $org = $request->user()->organization;
            $settings = $org->settings ?? [];
            $webhookUrl = $settings['siem_webhook_url'] ?? null;

            if (empty($webhookUrl)) {
                return response()->json([
                    'success' => false,
                    'is_missing_config' => true,
                    'message' => 'SIEM Webhook URL belum dikonfigurasi. Silakan atur di integrasi terlebih dahulu.',
                ], 400);
            }
            
            $payload = [
                'event_source' => 'PRIVASIMU',
                'event_type' => 'Data Breach Incident',
                'incident_code' => $breach->incident_code,
                'severity' => $breach->severity,
                'title' => $breach->title,
                'description' => $breach->description,
                'status' => $breach->status,
                'affected_subjects' => $breach->affected_subjects_count,
                'timestamp' => now()->toIso8601String(),
                'original_detected_at' => $breach->detected_at,
            ];

            $response = Http::post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info("SIEM Sync Success for Breach {$id}");
                return response()->json([
                    'success' => true,
                    'message' => 'IOC successfully broadcasted to SIEM/SOAR.',
                    'response' => $response->json()
                ]);
            }

            throw new \Exception($response->body());

        } catch (\Exception $e) {
            Log::error("SIEM Integration Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed to sync to SIEM: ' . $e->getMessage()], 500);
        }
    }
}
