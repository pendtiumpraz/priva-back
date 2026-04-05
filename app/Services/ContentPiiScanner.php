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
}
