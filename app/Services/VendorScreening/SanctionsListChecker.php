<?php

namespace App\Services\VendorScreening;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cek nama vendor di daftar sanksi global publik (gratis, JSON/CSV).
 *
 * Sumber yang di-cek (semua public domain, tidak butuh API key):
 *   - OFAC SDN list (Office of Foreign Assets Control, US Treasury)
 *   - UN Sanctions consolidated list
 *
 * Strategi match: substring case-insensitive setelah normalisasi nama
 * (strip PT, CV, koma, dst). False positive mungkin terjadi untuk nama
 * generik — finding di-tandai sebagai "potential_match" bukan "confirmed",
 * dan reviewer harus konfirmasi.
 *
 * Cache: list di-fetch + cache 24 jam (rotated daily, ukuran ~10MB).
 */
class SanctionsListChecker
{
    private const CACHE_TTL_HOURS = 24;
    private const OFAC_URL = 'https://www.treasury.gov/ofac/downloads/sdn.csv';
    private const UN_URL = 'https://scsanctions.un.org/resources/xml/en/consolidated.xml';

    /**
     * Cek apakah vendor name muncul di daftar sanksi.
     *
     * Return: array of hits, empty kalau bersih.
     * [
     *   ['list' => 'OFAC', 'matched_name' => '...', 'confidence' => 'high|medium|low', 'reason' => '...'],
     *   ...
     * ]
     */
    public function check(string $vendorName): array
    {
        $normalized = $this->normalize($vendorName);
        if (mb_strlen($normalized) < 4) {
            // Nama terlalu pendek, false positive risk terlalu tinggi
            return [];
        }

        $hits = [];

        try {
            $ofacEntries = $this->loadOfac();
            foreach ($ofacEntries as $entry) {
                if (str_contains($entry, $normalized)) {
                    $hits[] = [
                        'list' => 'OFAC SDN',
                        'matched_name' => mb_substr($entry, 0, 200),
                        'confidence' => $this->scoreConfidence($entry, $normalized),
                        'reason' => 'Vendor name muncul di OFAC SDN sanctions list.',
                    ];
                    if (count($hits) >= 5) break; // limit untuk readability
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SanctionsListChecker OFAC fetch failed: '.$e->getMessage());
        }

        try {
            $unEntries = $this->loadUn();
            foreach ($unEntries as $entry) {
                if (str_contains($entry, $normalized)) {
                    $hits[] = [
                        'list' => 'UN Consolidated',
                        'matched_name' => mb_substr($entry, 0, 200),
                        'confidence' => $this->scoreConfidence($entry, $normalized),
                        'reason' => 'Vendor name muncul di UN consolidated sanctions list.',
                    ];
                    if (count($hits) >= 10) break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SanctionsListChecker UN fetch failed: '.$e->getMessage());
        }

        return $hits;
    }

    private function normalize(string $name): string
    {
        $n = mb_strtolower(trim($name));
        // Strip company suffixes umum
        $n = preg_replace('/\b(pt|cv|tbk|persero|inc|ltd|llc|gmbh|corp|co|sdn|bhd)\b/i', '', $n);
        $n = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        return trim($n);
    }

    /**
     * Confidence score: kalau exact match (full token), high; kalau substring
     * di tengah string yang lebih panjang, lower.
     */
    private function scoreConfidence(string $entry, string $needle): string
    {
        $entryNorm = $this->normalize($entry);
        if ($entryNorm === $needle) {
            return 'high';
        }
        $tokens = explode(' ', $entryNorm);
        if (in_array($needle, $tokens, true)) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Load OFAC SDN list (CSV) → array of normalized entry strings.
     * Cache 24 jam. CSV ~5-10 MB, parse jadi list nama.
     */
    private function loadOfac(): array
    {
        return Cache::remember('vendor_screening.ofac_list', now()->addHours(self::CACHE_TTL_HOURS), function () {
            try {
                $res = Http::timeout(30)->withoutVerifying()->get(self::OFAC_URL);
                if ($res->failed()) {
                    return [];
                }
                $csv = $res->body();
                $lines = explode("\n", $csv);
                $out = [];
                foreach ($lines as $line) {
                    // OFAC CSV format: ent_num,SDN_Name,SDN_Type,Program,Title,...
                    // Kita ambil kolom 2 (SDN_Name) — index 1 (0-based)
                    $cols = str_getcsv($line);
                    if (isset($cols[1]) && trim($cols[1]) !== '') {
                        $out[] = $this->normalize($cols[1]);
                    }
                }
                return array_values(array_unique(array_filter($out)));
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Load UN consolidated list (XML) → array of normalized entry strings.
     */
    private function loadUn(): array
    {
        return Cache::remember('vendor_screening.un_list', now()->addHours(self::CACHE_TTL_HOURS), function () {
            try {
                $res = Http::timeout(30)->withoutVerifying()->get(self::UN_URL);
                if ($res->failed()) {
                    return [];
                }
                $xml = $res->body();
                // Quick & dirty extract <FIRST_NAME> + <SECOND_NAME> + <THIRD_NAME> + <ENTITY_NAME>
                // tanpa full XML parsing untuk efisiensi
                preg_match_all('/<(?:FIRST_NAME|SECOND_NAME|THIRD_NAME|FOURTH_NAME|ENTITY_NAME)>([^<]+)</', $xml, $matches);
                $names = $matches[1] ?? [];
                $out = [];
                foreach ($names as $n) {
                    $norm = $this->normalize($n);
                    if (mb_strlen($norm) >= 3) {
                        $out[] = $norm;
                    }
                }
                return array_values(array_unique($out));
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}
