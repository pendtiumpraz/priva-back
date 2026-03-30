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

            // In a real app the token/chat_id might come from the organization settings or .env
            $token = env('TELEGRAM_BOT_TOKEN', 'mock_token');
            $chatId = env('TELEGRAM_CHAT_ID', 'mock_chat_id');

            // Format message
            $message = "🚨 *INCIDENT ALERT: " . mb_strtoupper((string) $breach->severity) . "* 🚨\n\n";
            $message .= "*Incident Code:* " . $breach->incident_code . "\n";
            $message .= "*Title:* " . $breach->title . "\n";
            $message .= "*Status:* " . mb_strtoupper((string) $breach->status) . "\n";
            $message .= "*Detected At:* " . $breach->detected_at . "\n\n";
            $message .= "*Description:*\n" . $breach->description . "\n\n";
            $message .= "🔒 _Please check the Privasimu Dashboard for more details._";

            // If we don't have real credentials, just mock the success!
            if ($token === 'mock_token') {
                return response()->json([
                    'success' => true,
                    'message' => 'Telegram message generated successfully (Mock mode). Add TELEGRAM_BOT_TOKEN in .env for real sending.',
                    'sent_payload' => $message
                ]);
            }

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

            $webhookUrl = env('SIEM_WEBHOOK_URL', 'mock_webhook');
            
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

            if ($webhookUrl === 'mock_webhook') {
                return response()->json([
                    'success' => true,
                    'message' => 'SIEM IOC Payload generated successfully (Mock mode). Add SIEM_WEBHOOK_URL in .env to broadcast.',
                    'sent_payload' => $payload
                ]);
            }

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
