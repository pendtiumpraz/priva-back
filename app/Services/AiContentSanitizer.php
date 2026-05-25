<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Anti-prompt-injection content sanitizer untuk semua AI surface
 * (AI Agent, AI Chat Widget, VRM Avatar).
 *
 * Public utility yang previously private-static di AiAgentToolExecutor.
 * Di-extract supaya 3 AI surface inherit defense yang SAMA — tidak ada
 * lagi asymmetric protection di mana Surface 1 (AI Agent) punya 11-layer
 * defense tapi Surface 2 (Chat Widget) + 3 (Avatar) zero sanitize.
 *
 * Lihat juga audit `PLATFORM_FEATURE_AUDIT.md` + `AI_SECURITY_AUDIT.md`.
 *
 * Defense layers:
 *  1. PII redaction recursive (sanitizeForAi) — password/email/NIK/phone/name
 *  2. Prompt injection neutralizer (neutralizePromptInjection):
 *     - Control chars + zero-width Unicode
 *     - Encoded blobs: morse, base64 ≥60 char, hex ≥40 char, ROT13 caps ≥30 char
 *     - Role-tokens: SYSTEM:/ASSISTANT:/USER:/TOOL: di awal baris
 *     - Markdown fence dengan system tag
 *     - Custom delimiters spoofing (=== END / AKHIR / MULAI ===)
 *     - 11 jailbreak phrase pattern (ID + EN)
 *  3. Content truncation untuk free-text >200 char
 *
 * Cara pakai:
 *   $clean = AiContentSanitizer::neutralize($userInput);
 *   $clean = AiContentSanitizer::sanitizeForAi($toolOutput);
 */
class AiContentSanitizer
{
    /**
     * Recursive sanitizer untuk tool result / array of records sebelum
     * masuk ke AI context. Aware terhadap field name (mask berdasarkan
     * key: email, phone, nik, password, dll).
     *
     * Free-text field (description, notes, response) di-neutralize untuk
     * cegah prompt injection via DB content.
     *
     * @param  mixed  $data  array atau scalar
     * @return mixed
     */
    public static function sanitizeForAi($data)
    {
        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            $out = [];
            foreach ($data as $k => $v) {
                if ($isAssoc && is_string($k)) {
                    $out[$k] = self::sanitizeField((string) $k, $v);
                } else {
                    $out[$k] = self::sanitizeForAi($v);
                }
            }

            return $out;
        }

        return $data;
    }

    /**
     * Sanitize satu field berdasarkan field name (key) + value.
     * Mask sesuai PII category, lalu neutralize prompt injection untuk
     * free-text panjang.
     */
    public static function sanitizeField(string $key, $value)
    {
        if (is_array($value)) {
            return self::sanitizeForAi($value);
        }
        if (! is_string($value)) {
            return $value;
        }

        $k = strtolower($key);

        // Full redact: credentials, tokens, IDs where knowing the value is pure PII
        if (preg_match('/(password|secret|token|api_key|credit_card|cvv|rekening|bank_account)/', $k)) {
            return '[REDACTED]';
        }

        // National ID numbers (NIK/KTP): keep only first 4 + last 2
        if (preg_match('/(^|_)(nik|ktp|national_id|identity_number)($|_)/', $k)) {
            return self::maskDigits($value, 4, 2);
        }

        // Email → keep domain only
        if (preg_match('/(^|_)(email|mail|e_mail)($|_)/', $k) && str_contains($value, '@')) {
            [, $domain] = array_pad(explode('@', $value, 2), 2, '');
            return '***@'.$domain;
        }

        // Phone-like → mask middle digits
        if (preg_match('/(phone|telepon|telp|handphone|hp|mobile|whatsapp|wa_number)/', $k)) {
            return self::maskDigits($value, 2, 2);
        }

        // Name-ish or address → partial mask
        if (preg_match('/(^name$|_name$|^nama$|_nama$|requester_name|full.?name|first.?name|last.?name|address|alamat)/', $k)) {
            return self::maskString($value, 2);
        }

        // Free-text fields — neutralize prompt-injection pattern
        $value = self::neutralize($value);

        // Long narrative — truncate
        if (strlen($value) > 200) {
            return substr($value, 0, 200).'… [truncated for privacy]';
        }

        return $value;
    }

    /**
     * Strip prompt injection patterns dari free-text. Pakai untuk:
     * - DB content yang akan masuk system prompt (KB section, RoPA desc)
     * - User uploaded file content
     * - Knowledge Base chunk dari findRelevant()
     *
     * Idempotent — safe to call multiple times. Logs warning when strips occur.
     */
    public static function neutralize(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $original = $text;
        $stripCount = 0;

        // (1) Strip control chars kecuali tab/newline/CR
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // (2) Zero-width chars / BOM / right-to-left override
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $text) ?? $text;

        // (3) Morse code — sequence panjang dot/dash/slash/spasi
        $text = preg_replace_callback('/(?:[.\-]{1,8}[\s\/]){6,}[.\-]{1,8}/u', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:morse⟧';
        }, $text) ?? $text;

        // (4) Base64 blob — turunkan threshold ke 32 char (lebih agresif).
        //     P1 audit fix: base64 60-char split-bypass.
        $text = preg_replace_callback('/[A-Za-z0-9+\/]{32,}={0,2}/', function ($m) use (&$stripCount) {
            // Verify it's actually base64-decodable (mostly) — skip pure alphanumeric run
            $candidate = $m[0];
            if (preg_match('/^[A-Za-z]{32,}$/', $candidate) && ! preg_match('/[A-Z][a-z]|[a-z][A-Z]/', $candidate)) {
                // Pure uppercase or pure lowercase alphabet >= 32 char without mix — likely word
                return $candidate;
            }
            $stripCount++;
            return '⟦encoded:base64⟧';
        }, $text) ?? $text;

        // (5) Hex blob — turunkan ke 24 char
        $text = preg_replace_callback('/\b[0-9a-fA-F]{24,}\b/', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:hex⟧';
        }, $text) ?? $text;

        // (6) ROT13-like — all-caps tanpa spasi panjang
        $text = preg_replace_callback('/\b[A-Z]{30,}\b/', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:caps-blob⟧';
        }, $text) ?? $text;

        // (6b) Common ROT13 marker words (qvp/svaq/jbeyq/pbagvag) — mixed case yang skip rule 6
        $text = preg_replace_callback('/\b(?:qvp|svaq|cbnff|jbeyq|hfre|cbffjbeq|cbgrz|nffvfgnag|flfgrz|nqzva)\b/i', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:rot13⟧';
        }, $text) ?? $text;

        // (7) Role-token impersonation pada awal baris atau setelah newline
        $text = preg_replace('/(^|\n)\s*(SYSTEM|ASSISTANT|USER|TOOL|FUNCTION|DEVELOPER)\s*:\s*/i', '$1[role-strip] ', $text) ?? $text;

        // (8) Markdown fenced dengan system/role tag
        $text = preg_replace('/```\s*(?:system|role|instruction|prompt|developer)\b/i', '[fence-strip]', $text) ?? $text;

        // (9) Custom delimiters spoofing (=== END / AKHIR / MULAI ===)
        $text = preg_replace('/(===\s*(?:END|AKHIR|BEGIN|MULAI|START|SELESAI|FINISH)\b[^\n]*===)/iu', '⟦marker-strip⟧', $text) ?? $text;

        // (10) Common jailbreak phrases
        $jailbreakPhrases = [
            '/ignore (?:all )?(?:previous|prior|above) (?:instructions?|prompts?|rules?)/i',
            '/disregard (?:all )?(?:previous|prior|above)/i',
            '/abaikan (?:semua )?(?:instruksi|perintah|aturan) (?:sebelumnya|di atas)/i',
            '/lupakan (?:semua )?(?:instruksi|perintah|aturan) (?:sebelumnya|di atas)/i',
            '/forget (?:all )?(?:previous|prior|earlier)/i',
            '/you are (?:now )?(?:a )?(?:new|different|jailbroken)/i',
            '/act as (?:a )?(?:DAN|developer mode|unrestricted|jailbroken)/i',
            '/(?:bypass|override|disable) (?:safety|guardrail|filter|approval|security)/i',
            '/(?:reveal|show|print|leak|expose) (?:your |the )?(?:system )?(?:prompt|instructions?|rules?)/i',
            '/pretend (?:to be |you are )?(?:DAN|jailbroken|unrestricted)/i',
            '/from now on (?:you |act )/i',
        ];
        foreach ($jailbreakPhrases as $p) {
            $text = preg_replace($p, '[jailbreak-strip]', $text) ?? $text;
        }

        // (11) Collapse 3+ consecutive newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        if ($stripCount > 0 || $text !== $original) {
            Log::warning('AiContentSanitizer neutralized suspicious content', [
                'strip_count' => $stripCount,
                'orig_len' => strlen($original),
                'after_len' => strlen($text),
            ]);
        }

        return $text;
    }

    /**
     * Backwards-compat alias — beberapa code lain mungkin import method ini
     * dengan nama lama. Direct delegate.
     */
    public static function neutralizePromptInjection(string $text): string
    {
        return self::neutralize($text);
    }

    private static function maskDigits(string $value, int $keepStart, int $keepEnd): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $len = strlen($digits);
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat('*', $len);
        }

        return substr($digits, 0, $keepStart)
            .str_repeat('*', $len - $keepStart - $keepEnd)
            .substr($digits, -$keepEnd);
    }

    private static function maskString(string $value, int $keepStart): string
    {
        $len = strlen($value);
        if ($len <= $keepStart + 1) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, $keepStart).str_repeat('*', $len - $keepStart);
    }
}
