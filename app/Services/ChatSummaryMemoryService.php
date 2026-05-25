<?php

namespace App\Services;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;

/**
 * Conversation Summary Buffer Memory service untuk AI surfaces.
 *
 * Pattern dari LangChain ConversationSummaryBufferMemory:
 *   - Last N message: full content (recent context)
 *   - Older messages: di-compress jadi 1 summary block (older context)
 *
 * LLM diminta generate response dalam format JSON `{reply, summary}`.
 * Summary di-save di kolom chat_messages.summary, tidak ditampilkan ke FE.
 * Saat load history untuk next request, summary message di-aggregate jadi
 * 1 system message "[Ringkasan percakapan sebelumnya]".
 *
 * Benefit: bisa percakapan 50+ turn tanpa hit prompt size limit, sambil
 * tetap preserve konteks lama dalam bentuk compressed.
 *
 * Pakai di: AiAgentController, AiChatController, AvatarChatController.
 */
class ChatSummaryMemoryService
{
    /** Berapa message terakhir yang di-load full (no summary compression). */
    public const RECENT_WINDOW = 10;

    /**
     * Instruksi tambahan untuk system prompt: minta LLM output JSON dengan
     * field `reply` (visible) + `summary` (hidden 20-30 kata).
     *
     * Inject ke system prompt setiap call. Provider yang support JSON mode
     * (DeepSeek, OpenAI, Anthropic via beta) akan enforce schema.
     */
    public static function getJsonModeInstruction(): string
    {
        return <<<'INSTRUCTION'

FORMAT OUTPUT WAJIB JSON:
Kembalikan response SELALU dalam format JSON object dengan 2 field:
{
  "reply": "<jawaban lengkap untuk user, boleh markdown, boleh panjang>",
  "summary": "<ringkasan 20-30 kata Bahasa Indonesia tentang topik + keputusan turn ini, untuk memory percakapan>"
}

Contoh:
{"reply": "Berdasarkan RoPA Anda, risk level HIGH karena cross-border. Saya sarankan buat DPIA segera.", "summary": "User tanya analisis ROPA-2026-005, AI flag risk HIGH karena cross-border, sarankan DPIA"}

JANGAN tambahkan komentar di luar JSON. JANGAN escape kurung kurawal di dalam reply yang akan rusak JSON parsing.
INSTRUCTION;
    }

    /**
     * Parse LLM response yang seharusnya JSON {reply, summary}.
     * Robust: fallback ke plain text kalau JSON parse gagal.
     *
     * @return array{reply: string, summary: ?string}
     */
    public static function parseResponse(string $rawResponse): array
    {
        $trimmed = trim($rawResponse);

        // Try strict JSON parse
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && isset($decoded['reply'])) {
            return [
                'reply' => (string) $decoded['reply'],
                'summary' => isset($decoded['summary']) ? (string) $decoded['summary'] : null,
            ];
        }

        // Try extract first JSON object dari response (kalau ada wrapping text)
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $trimmed, $match)) {
            $maybeJson = $match[0];
            $decoded = json_decode($maybeJson, true);
            if (is_array($decoded) && isset($decoded['reply'])) {
                return [
                    'reply' => (string) $decoded['reply'],
                    'summary' => isset($decoded['summary']) ? (string) $decoded['summary'] : null,
                ];
            }
        }

        // Fallback: plain text — LLM tidak comply JSON format
        Log::info('ChatSummaryMemory: LLM response not in JSON format, fallback to plain text', [
            'sample' => substr($trimmed, 0, 200),
        ]);

        return [
            'reply' => $trimmed,
            'summary' => null,
        ];
    }

    /**
     * Build messages array untuk LLM dengan summary memory pattern.
     *
     * Strategi:
     *   1. Ambil semua chat_messages di conversation ini, oldest first
     *   2. Kalau >= RECENT_WINDOW messages: split jadi older + recent
     *   3. Older: aggregate summary jadi 1 "context summary" system message
     *   4. Recent: include full content (sanitized untuk anti-injection)
     *
     * @param string $conversationId
     * @return array<int, array{role: string, content: string}>
     */
    public static function buildHistoryMessages(string $conversationId): array
    {
        $allMessages = ChatMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get();

        if ($allMessages->isEmpty()) {
            return [];
        }

        $total = $allMessages->count();
        $messages = [];

        if ($total > self::RECENT_WINDOW) {
            // Split: older (need summary) + recent (full)
            $olderCount = $total - self::RECENT_WINDOW;
            $older = $allMessages->take($olderCount);

            // Aggregate summary dari older messages
            $summaries = $older->filter(function ($m) {
                return ! empty($m->summary);
            })->map(function ($m) {
                $role = $m->role === 'assistant' ? 'AI' : 'User';
                return "- {$role}: {$m->summary}";
            })->implode("\n");

            if (! empty($summaries)) {
                // Sanitize aggregated summary untuk anti-injection
                $safeSummary = AiContentSanitizer::neutralize($summaries);
                $messages[] = [
                    'role' => 'system',
                    'content' => "[Ringkasan percakapan sebelumnya — {$olderCount} pesan]:\n{$safeSummary}",
                ];
            } else {
                // Fallback kalau older messages tidak punya summary (legacy data)
                // Drop saja — sliding window
                Log::info('ChatSummaryMemory: older messages lack summary, dropping (sliding window)', [
                    'conversation_id' => $conversationId,
                    'dropped_count' => $olderCount,
                ]);
            }

            $recent = $allMessages->skip($olderCount);
        } else {
            $recent = $allMessages;
        }

        // Append recent messages (full content, sanitized)
        foreach ($recent as $msg) {
            $messages[] = [
                'role' => $msg->role === 'admin' ? 'assistant' : $msg->role,
                'content' => AiContentSanitizer::neutralize((string) $msg->content),
            ];
        }

        return $messages;
    }
}
