<?php

namespace App\Services;

/**
 * Static industry benchmark data for GAP Assessment.
 *
 * Returns per-category target percentages used for the "Bandingkan ke
 * Standar Industri" comparison flow. Values are curated baselines — not
 * statistical samples. Tune these as Privasimu collects real adoption
 * data per industry.
 *
 * Categories mirror the GAP question bank (UU PDP):
 *   - "Tata Kelola"        — governance maturity
 *   - "Siklus Proses PDP"  — operational lifecycle compliance
 */
class GapBenchmarkService
{
    /** Per-industry per-category target % (UU PDP). */
    private const INDUSTRY_BENCHMARKS = [
        // High-regulation sectors — should aim higher
        'banking' => ['Tata Kelola' => 85, 'Siklus Proses PDP' => 88],
        'finance' => ['Tata Kelola' => 85, 'Siklus Proses PDP' => 88],
        'fintech' => ['Tata Kelola' => 80, 'Siklus Proses PDP' => 82],
        'insurance' => ['Tata Kelola' => 80, 'Siklus Proses PDP' => 82],
        'healthcare' => ['Tata Kelola' => 80, 'Siklus Proses PDP' => 85],
        'pharmaceutical' => ['Tata Kelola' => 78, 'Siklus Proses PDP' => 82],
        'telecommunications' => ['Tata Kelola' => 75, 'Siklus Proses PDP' => 78],
        'government' => ['Tata Kelola' => 78, 'Siklus Proses PDP' => 75],

        // Medium-regulation sectors
        'energy' => ['Tata Kelola' => 70, 'Siklus Proses PDP' => 70],
        'transportation' => ['Tata Kelola' => 65, 'Siklus Proses PDP' => 68],
        'logistics' => ['Tata Kelola' => 65, 'Siklus Proses PDP' => 68],
        'ecommerce' => ['Tata Kelola' => 70, 'Siklus Proses PDP' => 72],
        'retail' => ['Tata Kelola' => 60, 'Siklus Proses PDP' => 62],
        'media' => ['Tata Kelola' => 60, 'Siklus Proses PDP' => 60],
        'technology' => ['Tata Kelola' => 70, 'Siklus Proses PDP' => 72],
        'education' => ['Tata Kelola' => 60, 'Siklus Proses PDP' => 62],
        'manufacturing' => ['Tata Kelola' => 55, 'Siklus Proses PDP' => 58],
        'agriculture' => ['Tata Kelola' => 50, 'Siklus Proses PDP' => 50],

        // Catch-all default
        '__default__' => ['Tata Kelola' => 65, 'Siklus Proses PDP' => 68],
    ];

    /**
     * UU PDP minimum compliance threshold per category.
     * Below this, the org is considered non-compliant with UU PDP.
     */
    private const UUPDP_MINIMUM = ['Tata Kelola' => 70, 'Siklus Proses PDP' => 70];

    /** List of supported industries for FE dropdown. */
    public static function listIndustries(): array
    {
        return array_values(array_filter(array_keys(self::INDUSTRY_BENCHMARKS), fn ($k) => $k !== '__default__'));
    }

    /**
     * Per-category benchmark scores for a given industry tag.
     * Falls back to __default__ when industry is unknown/null.
     */
    public static function industryScores(?string $industry): array
    {
        $key = strtolower(trim($industry ?? ''));

        return self::INDUSTRY_BENCHMARKS[$key] ?? self::INDUSTRY_BENCHMARKS['__default__'];
    }

    /** UU PDP minimum compliance per category. */
    public static function uupdpMinimum(): array
    {
        return self::UUPDP_MINIMUM;
    }

    /**
     * Build the benchmark series for a regulation. Currently uupdp only;
     * other regulation_codes fall back to default scores.
     */
    public static function buildSeriesFor(?string $industry, string $regulationCode = 'uupdp'): array
    {
        return [
            'industry' => self::industryScores($industry),
            'minimum' => self::uupdpMinimum(),
            'industry_label' => self::industryLabel($industry),
        ];
    }

    private static function industryLabel(?string $industry): string
    {
        $key = strtolower(trim($industry ?? ''));
        if ($key === '' || ! array_key_exists($key, self::INDUSTRY_BENCHMARKS)) {
            return 'Standar Umum';
        }

        return ucfirst($key);
    }
}
