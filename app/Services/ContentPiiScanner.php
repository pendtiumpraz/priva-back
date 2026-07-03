<?php

namespace App\Services;

class ContentPiiScanner
{
    /**
     * Map of regex patterns to PII classifications
     */
    private static function getPatterns(): array
    {
        return [
            // NIK (16 digits)
            'nik' => [
                'pattern' => '/^\d{16}$/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 1.0,
                'reason' => 'Pola NIK (16 digit) terdeteksi di isi data'
            ],
            // NPWP (15-16 digits with or without separators)
            'npwp' => [
                'pattern' => '/^(\d{2}[\.\-]?\d{3}[\.\-]?\d{3}[\.\-]?\d{1}[\.\-]?\d{3}[\.\-]?\d{3}|\d{15,16})$/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.8,
                'reason' => 'Pola NPWP terdeteksi di isi data'
            ],
            // Email address
            'email' => [
                'pattern' => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.9,
                'reason' => 'Alamat Email terdeteksi di isi data'
            ],
            // Indonesian Phone Number
            'phone' => [
                'pattern' => '/^(\+62|62|0)8[1-9][0-9]{6,10}$/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.8,
                'reason' => 'Nomor Telepon Indonesia terdeteksi di isi data'
            ],
            // Credit Card (basic approximation)
            'credit_card' => [
                'pattern' => '/^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|3(?:0[0-5]|[68][0-9])[0-9]{11}|6(?:011|5[0-9]{2})[0-9]{12}|(?:2131|1800|35\d{3})\d{11})$/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 1.0,
                'reason' => 'Nomor Kartu Kredit terdeteksi di isi data'
            ],
            // IP Address
            'ip_address' => [
                'pattern' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 1.0,
                'reason' => 'Alamat IP (IPv4) terdeteksi di isi data'
            ]
        ];
    }

    /**
     * Sample rows and return the overall PII classification for the column
     */
    public static function analyzeColumnContent(array $sampledValues): ?array
    {
        $patterns = self::getPatterns();
        $totalSamples = count($sampledValues);
        if ($totalSamples === 0) return null;

        $matchCounts = array_fill_keys(array_keys($patterns), 0);
        $totalNonNull = 0;

        foreach ($sampledValues as $val) {
            if ($val === null || $val === '') continue;
            $val = (string) $val;
            $totalNonNull++;

            foreach ($patterns as $key => $config) {
                if (preg_match($config['pattern'], trim($val))) {
                    $matchCounts[$key]++;
                }
            }
        }

        if ($totalNonNull === 0) return null;

        // If a pattern matches more than 15% of non-null rows, consider it a match
        $bestMatch = null;
        $highestConfidence = 0;

        foreach ($matchCounts as $key => $count) {
            $confidence = $count / $totalNonNull;
            if ($confidence > 0.15 && $confidence > $highestConfidence) {
                $highestConfidence = $confidence;
                $bestMatch = $key;
            }
        }

        if ($bestMatch) {
            $config = $patterns[$bestMatch];
            return [
                'is_pii' => true,
                'pdp_category' => $config['pdp_category'],
                'classification' => $config['classification'],
                'encryption_required' => $config['encryption_required'],
                'reason' => $config['reason'] . ' (Akurasi: ' . round($highestConfidence * 100) . '%)',
                'shadow_detected' => true,
            ];
        }

        return null;
    }

    /**
     * Tentukan STATUS PROTEKSI kolom dari sampel nilainya (LOKAL — tidak ke AI).
     * Cukup beberapa nilai non-null; untuk deteksi enkripsi/masking 1 nilai
     * biasanya sudah cukup. Return protection_state:
     *   plaintext  🔴 nilai PII terbaca telanjang
     *   masked     🟡 sudah disamarkan (***, partial)
     *   encrypted  🟢 sudah dienkripsi (blob base64/hex / Laravel Crypt)
     *   mixed      ⚠️ campur (sebagian terlindung, sebagian plaintext) → risiko
     *   unknown    tak ada nilai non-null pada sampel
     */
    public static function detectProtectionState(array $sampledValues): array
    {
        $vals = [];
        foreach ($sampledValues as $v) {
            if ($v === null || $v === '') continue;
            $vals[] = (string) $v;
        }
        if (empty($vals)) {
            return ['protection_state' => 'unknown', 'protection_reason' => 'Tidak ada nilai non-null pada sampel'];
        }

        $enc = 0; $mask = 0; $plain = 0;
        foreach ($vals as $v) {
            if (self::looksEncrypted($v)) { $enc++; }
            elseif (self::looksMasked($v)) { $mask++; }
            else { $plain++; }
        }
        $n = count($vals);

        if ($enc === $n) {
            return ['protection_state' => 'encrypted', 'protection_reason' => "Nilai tampak terenkripsi (blob) — $n/$n sampel"];
        }
        if ($mask === $n) {
            return ['protection_state' => 'masked', 'protection_reason' => "Nilai tampak ter-masking — $n/$n sampel"];
        }
        if ($plain === $n) {
            return ['protection_state' => 'plaintext', 'protection_reason' => "Nilai terbaca (plaintext) — $n/$n sampel"];
        }
        // Campuran → tandai sebagai risiko (migrasi setengah jalan / tidak konsisten).
        return ['protection_state' => 'mixed', 'protection_reason' => "Tidak konsisten: $enc enkripsi, $mask masked, $plain plaintext (dari $n sampel)"];
    }

    /** Heuristik: nilai tampak TERENKRIPSI (ciphertext) — LOKAL, tanpa dekripsi. */
    private static function looksEncrypted(string $v): bool
    {
        $v = trim($v);
        // Laravel Crypt: base64 → JSON {iv, value, mac}. Sinyal paling kuat.
        $dec = base64_decode($v, true);
        if ($dec !== false && $dec !== '') {
            $j = json_decode($dec, true);
            if (is_array($j) && isset($j['iv'], $j['value']) && (isset($j['mac']) || isset($j['tag']))) {
                return true;
            }
        }
        // Ada spasi / terbaca → hampir pasti bukan ciphertext.
        if (preg_match('/\s/', $v)) return false;
        // Hex panjang (mis. SHA/AES hex).
        if (preg_match('/^[0-9a-fA-F]{32,}$/', $v)) return true;
        // Base64 panjang + entropi tinggi.
        if (preg_match('/^[A-Za-z0-9+\/]{24,}={0,2}$/', $v) && self::entropy($v) >= 3.6) return true;
        return false;
    }

    /** Heuristik: nilai tampak SUDAH DI-MASKING. */
    private static function looksMasked(string $v): bool
    {
        // Asterisk / bullet / hash run (mis. 1234****, b***@mail.com, ****).
        if (preg_match('/\*{2,}|•{2,}|#{4,}/u', $v)) return true;
        // Partial reveal digit dengan x: 08xx-xxxx-1234 / 12xxxx56 (>=3 x berturut).
        if (preg_match('/\dx{3,}\d|x{4,}/i', $v) && preg_match('/[0-9]/', $v)) return true;
        return false;
    }

    /** Shannon entropy (bit/char) — untuk membedakan blob acak vs teks biasa. */
    private static function entropy(string $s): float
    {
        $len = strlen($s);
        if ($len === 0) return 0.0;
        $freq = count_chars($s, 1);
        $h = 0.0;
        foreach ($freq as $c) {
            $p = $c / $len;
            $h -= $p * log($p, 2);
        }
        return $h;
    }
}
