<?php

namespace App\Services;

use App\Exceptions\PromptTooLargeException;

/**
 * Validator untuk size prompt AI sebelum di-kirim ke provider.
 *
 * Tujuan utama: cegah biaya tak terduga + abuse. AI provider charge per
 * token; tanpa guard ini, attacker authenticated bisa kirim prompt 100K
 * char untuk drain credit / bikin tagihan membengkak. CreditService
 * tetap jadi quota tier ke-2 (post-hoc), tapi guard ini tier pertama
 * (REJECT sebelum HTTP call ke provider).
 *
 * Limit dibaca dari config('security.ai.*'), yang di-hidrasi dari tabel
 * system_settings via SettingsServiceProvider. Editable runtime lewat
 * UI /platform-admin/system-settings → Security → AI Limits.
 *
 * Approach char-count, BUKAN token count — alasan:
 *   1. Tokenizer (tiktoken-php) dependency tambah weight + slow O(n)
 *   2. Tokenizer beda per provider (OpenAI BPE != Claude tokenizer != ...)
 *   3. Char count bisa di-konversi konservatif: 1 char ≈ 0.25 token (English),
 *      lebih sedikit untuk Bahasa Indonesia. Kalau set max 24000 char,
 *      worst case ≈ 6000 token — lebih dari cukup margin sebelum context
 *      window provider yang biasanya 16K-200K.
 *
 * 3 batas terpisah:
 *   - prompt_chars: total system+user prompt yang sampai ke provider
 *   - message_chars: single user message field di chat / agent (input form)
 *   - attachment_chars: parsed text dari file upload (PDF/DOCX/XLS)
 */
class AiPromptGuard
{
    public const FIELD_PROMPT = 'prompt';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_ATTACHMENT = 'attachment';

    public function getMaxPromptChars(): int
    {
        return (int) config('security.ai.max_prompt_chars', 24000);
    }

    public function getMaxMessageChars(): int
    {
        return (int) config('security.ai.max_message_chars', 4000);
    }

    public function getMaxAttachmentChars(): int
    {
        return (int) config('security.ai.max_attachment_chars', 12000);
    }

    /**
     * Throw kalau combined prompt size > limit. Multibyte-safe.
     */
    public function assertPromptSize(string $combinedPrompt): void
    {
        $max = $this->getMaxPromptChars();
        if ($max <= 0) return; // 0 = disabled
        $actual = mb_strlen($combinedPrompt);
        if ($actual > $max) {
            throw new PromptTooLargeException($actual, $max, self::FIELD_PROMPT);
        }
    }

    /**
     * Throw kalau single user message field > limit.
     */
    public function assertMessageSize(string $message): void
    {
        $max = $this->getMaxMessageChars();
        if ($max <= 0) return;
        $actual = mb_strlen($message);
        if ($actual > $max) {
            throw new PromptTooLargeException($actual, $max, self::FIELD_MESSAGE);
        }
    }

    /**
     * Truncate file attachment text ke limit. Tidak throw — file besar
     * adalah use case sah (PDF compliance ratusan halaman); user expect
     * sebagian di-skip dengan footer note daripada upload-nya gagal total.
     *
     * Returns: array { text: string, truncated: bool, original_chars: int, kept_chars: int }
     */
    public function truncateAttachment(string $text): array
    {
        $max = $this->getMaxAttachmentChars();
        $original = mb_strlen($text);
        if ($max <= 0 || $original <= $max) {
            return [
                'text' => $text,
                'truncated' => false,
                'original_chars' => $original,
                'kept_chars' => $original,
            ];
        }

        $kept = mb_substr($text, 0, $max);
        $note = sprintf(
            "\n\n[... dokumen terlalu panjang, dipotong dari %s ke %s karakter untuk hemat biaya AI ...]",
            number_format($original),
            number_format($max),
        );

        return [
            'text' => $kept.$note,
            'truncated' => true,
            'original_chars' => $original,
            'kept_chars' => $max,
        ];
    }

    /**
     * Untuk frontend: return aktif limit value (tanpa secret) supaya UI
     * bisa kasih indicator "X / Y char" di textarea live.
     *
     * @return array{max_prompt_chars:int,max_message_chars:int,max_attachment_chars:int}
     */
    public function getLimits(): array
    {
        return [
            'max_prompt_chars' => $this->getMaxPromptChars(),
            'max_message_chars' => $this->getMaxMessageChars(),
            'max_attachment_chars' => $this->getMaxAttachmentChars(),
        ];
    }
}
