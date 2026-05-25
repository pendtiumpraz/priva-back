<?php

namespace App\Services;

/**
 * Rule-based intent classifier untuk AI message.
 *
 * Tujuan: decide apakah tool calling diperlukan, sehingga bisa save
 * ~3700 token per request saat pure Q&A (no tools needed).
 *
 * Output 4 kategori intent:
 *   - PURE_QA      → tidak butuh tools (definisi, penjelasan, "apa itu X")
 *   - CRUD_ACTION  → butuh full tools (mutation: buat, update, hapus)
 *   - READ_ONLY    → butuh read tools saja (list, search, get detail)
 *   - AMBIGUOUS    → fallback ke full tools (defensive)
 *
 * Pattern detection (rule-based, no LLM call):
 *   1. @ mention reference → CRUD_ACTION (user reference specific record)
 *   2. Mutation keyword (buat, create, update, hapus, edit, ubah) → CRUD_ACTION
 *   3. Read keyword (list, tampilkan, cari, search, show, lihat) → READ_ONLY
 *   4. Question word (apa itu, jelaskan, definisi, kenapa) → PURE_QA
 *   5. Default → AMBIGUOUS (kirim full tools defensively)
 *
 * Cost: 0 (no LLM call). Latency: <1ms (pure regex). Accuracy: ~80%.
 */
class AiIntentClassifier
{
    public const PURE_QA = 'pure_qa';
    public const CRUD_ACTION = 'crud_action';
    public const READ_ONLY = 'read_only';
    public const AMBIGUOUS = 'ambiguous';

    /**
     * Classify intent dari user message.
     * Return array { intent: string, reason: string, has_mention: bool }
     */
    public static function classify(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['intent' => self::PURE_QA, 'reason' => 'empty message', 'has_mention' => false];
        }

        $lower = mb_strtolower($message);
        $hasMention = self::detectMention($message);

        // Rule 1: @ mention reference → user mau action pada specific record
        if ($hasMention) {
            return [
                'intent' => self::CRUD_ACTION,
                'reason' => 'detected @ mention reference',
                'has_mention' => true,
            ];
        }

        // Rule 2: Mutation keywords
        $mutationPatterns = [
            '/\b(buat|bikin|tambah|tambahkan|create|add|new)\b/i',
            '/\b(update|ubah|edit|ganti|modify|change|set)\b/i',
            '/\b(hapus|delete|remove|drop|kill|cancel)\b/i',
            '/\b(approve|reject|setuju|tolak|submit|kirim ke approver)\b/i',
            '/\b(simpan|save|publish|publikasi)\b/i',
        ];
        foreach ($mutationPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return [
                    'intent' => self::CRUD_ACTION,
                    'reason' => 'mutation keyword detected',
                    'has_mention' => false,
                ];
            }
        }

        // Rule 3: Read-only keywords (list/search/get)
        $readPatterns = [
            '/\b(list|tampilkan|lihat|show|display)\b/i',
            '/\b(cari|search|find|temukan)\b/i',
            '/\b(berapa|how many|count|jumlah|hitung)\b/i',
            '/\b(detail|info|informasi|status)\b/i',
            '/\b(ringkasan|summary|overview|laporan|report)\b/i',
        ];
        foreach ($readPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return [
                    'intent' => self::READ_ONLY,
                    'reason' => 'read-only keyword detected',
                    'has_mention' => false,
                ];
            }
        }

        // Rule 4: Pure Q&A patterns (definisi, penjelasan, knowledge query)
        $qaPatterns = [
            '/^(apa itu|apa saja|apa yang)\b/i',
            '/^(jelaskan|terangkan|definisi)\b/i',
            '/^(kenapa|mengapa|bagaimana cara)\b/i',
            '/^(what is|what are|explain|why|how to)\b/i',
            '/\b(uu pdp|pasal \d+|regulasi|hukum|legal)\b/i',
            '/\b(perbedaan|beda|difference|comparison)\b/i',
            '/\b(contoh|example|misalnya|seperti apa)\b/i',
        ];
        foreach ($qaPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return [
                    'intent' => self::PURE_QA,
                    'reason' => 'pure Q&A pattern detected',
                    'has_mention' => false,
                ];
            }
        }

        // Default fallback: AMBIGUOUS (defensive — kirim full tools)
        return [
            'intent' => self::AMBIGUOUS,
            'reason' => 'no clear pattern match',
            'has_mention' => false,
        ];
    }

    /**
     * Detect @ mention pattern (mis. @ROPA-2026-005, @DPIA-002, @BRC-001).
     */
    private static function detectMention(string $message): bool
    {
        return (bool) preg_match('/@(ROPA|DPIA|BRC|DSR|CNT|TIA|LIA|GAP|MTY)[-_]?\d+/i', $message)
            || (bool) preg_match('/@[a-z]+-?[\w-]+/i', $message);  // generic @something
    }

    /**
     * Quick boolean helper untuk caller yang hanya butuh "perlu tools atau tidak".
     */
    public static function needsTools(string $message): bool
    {
        $result = self::classify($message);
        return $result['intent'] !== self::PURE_QA;
    }

    /**
     * Whether read-only tools cukup atau perlu mutation tools juga.
     */
    public static function needsMutationTools(string $message): bool
    {
        $result = self::classify($message);
        return $result['intent'] === self::CRUD_ACTION || $result['intent'] === self::AMBIGUOUS;
    }
}
