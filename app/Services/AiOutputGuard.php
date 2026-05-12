<?php

namespace App\Services;

/**
 * Validator untuk OUTPUT (response body) dari provider AI.
 *
 * Tujuan: cegah skenario di mana provider AI mengabaikan `max_tokens` —
 * mis. user mengirim prompt "tulis huruf A sebanyak 100.000.000 kali",
 * dan provider mengirim balik string raksasa. Walau `max_tokens` di
 * payload sudah di-clamp, sebagian provider masih melebihi batas saat
 * pola repetitive sangat panjang (1 token bisa = 1 char untuk "AAA...").
 *
 * Guard ini menjadi tier defensif KEDUA di sisi response — tier pertama
 * tetap di prompt size guard (AiPromptGuard) + hard cap pada parameter
 * `max_tokens`. Kalau ketiga tier ini ditembus, output di-tolak dan
 * caller dapat exception jelas (bukan response kosong/null).
 *
 * Limit dibaca dari config('security.ai.*') yang di-hidrasi DB oleh
 * SettingsServiceProvider. Editable runtime via UI superadmin di
 * /platform-admin/system-settings → Security → AI Limits.
 *
 * 3 jenis deteksi:
 *   1. Total char count > max_output_chars
 *   2. Karakter sama berulang > 100x (mis. "AAAA...")
 *   3. Kata sama berulang > 50x (mis. "test test test...")
 *   4. Single line > 5000 char (unbounded loop output)
 */
class AiOutputGuard
{
    public function getMaxOutputChars(): int
    {
        return (int) config('security.ai.max_output_chars', 50_000);
    }

    public function getMaxOutputTokens(): int
    {
        return (int) config('security.ai.max_output_tokens', 4000);
    }

    public function isRepetitionDetectionEnabled(): bool
    {
        return (bool) config('security.ai.repetition_detection_enabled', true);
    }

    /**
     * Clamp caller-supplied `max_tokens` ke hard cap. Mencegah caller
     * (atau bug di domain method) lewatkan nilai 100000 ke provider.
     *
     * Return min(requested, hard_cap). 0/negative → fallback ke hard_cap.
     */
    public function clampMaxTokens(int $requested): int
    {
        $cap = $this->getMaxOutputTokens();
        if ($cap <= 0) return max(1, $requested); // 0 = disabled, jaga sane
        if ($requested <= 0) return $cap;
        return min($requested, $cap);
    }

    /**
     * Validate output content. Throws RuntimeException kalau output melewati
     * batas. Caller harus catch + degrade gracefully (mis. return fallback
     * message ke user, log untuk audit).
     *
     * Multibyte-safe — penting untuk konten Bahasa Indonesia.
     */
    public function assert(string $output): void
    {
        $maxChars = $this->getMaxOutputChars();
        if ($maxChars > 0) {
            $actual = mb_strlen($output);
            if ($actual > $maxChars) {
                throw new \RuntimeException(sprintf(
                    'Output AI melebihi batas %s karakter (aktual %s). Permintaan ditolak untuk mencegah pemborosan kuota.',
                    number_format($maxChars),
                    number_format($actual),
                ));
            }
        }

        if (! $this->isRepetitionDetectionEnabled()) {
            return;
        }

        // Deteksi karakter berulang (mis. "AAAAAA..." 100x).
        // 100x pengulangan satu karakter hampir tidak pernah muncul dalam
        // konten sah Bahasa Indonesia / English; threshold ini aman.
        if (preg_match('/(.)\1{100,}/u', $output)) {
            throw new \RuntimeException(
                'Output AI mengandung pola karakter berulang. Permintaan dianggap penyalahgunaan kuota.'
            );
        }

        // Deteksi kata berulang (mis. "test test test ..." 50x).
        // `(\b\w+\s+)\1{50,}` — kata + whitespace, lalu 50+ pengulangan
        // identik beruntun. False-positive sangat rendah untuk konten sah.
        if (preg_match('/(\b\w+\s+)\1{50,}/u', $output)) {
            throw new \RuntimeException(
                'Output AI mengandung pengulangan kata yang berlebihan. Permintaan dianggap penyalahgunaan kuota.'
            );
        }

        // Deteksi baris tunggal > 5000 char — biasanya indikator output AI
        // yang stuck di loop tanpa newline. JSON valid pun tidak biasanya
        // melebihi ini per baris (kecuali di-minify, tapi response chat di
        // sini berupa narasi/markdown).
        foreach (explode("\n", $output) as $line) {
            if (mb_strlen($line) > 5000) {
                throw new \RuntimeException(
                    'Output AI mengandung baris tunggal yang terlalu panjang (>5000 karakter).'
                );
            }
        }
    }

    /**
     * Versi non-throw — return bool. Berguna di stream callback yang tidak
     * bisa lempar exception (akan ke-render sebagai error frame di stream).
     */
    public function isSafe(string $output): bool
    {
        try {
            $this->assert($output);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
