<?php

namespace Database\Seeders;

use App\Models\CountryAdequacy;
use Illuminate\Database\Seeder;

/**
 * Seeds the country adequacy lookup. Working classification under UU PDP
 * Pasal 56 — Komdigi has not published an official adequacy list yet,
 * so we derive tiers from:
 *   - Tier 1 (adequate):   GDPR Article 45 adequacy decisions + ID itself
 *   - Tier 2 (comparable): ASEAN states with comparable PDP laws + a few
 *                           others with strong frameworks
 *   - Tier 3 (limited):    countries with PDP frameworks but enforcement
 *                           gaps or sovereign-access concerns
 *   - Tier 4 (none):       no PDP framework / significant state-access risk
 *
 * Default risk metric pre-fills (1-10 scale, high = risky) feed the TIA
 * wizard — operators can adjust freely after import.
 */
class CountryAdequacySeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->tierAdequate(),
            $this->tierComparable(),
            $this->tierLimited(),
            $this->tierNone(),
        );

        foreach ($rows as $row) {
            CountryAdequacy::query()->updateOrCreate(
                ['country_code' => $row['country_code']],
                $row,
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function tierAdequate(): array
    {
        $base = [
            'tier' => CountryAdequacy::TIER_ADEQUATE,
            'default_regulation_mismatch' => 2,
            'default_sovereign_access_risk' => 2,
            'default_admin_sanctions' => 2,
            'has_pdp_law' => true,
            'has_pdp_authority' => true,
            'recommended_safeguards_required' => false,
            'is_active' => true,
        ];

        // Indonesia first — domestic, technically not "transfer" but recorded
        // for completeness so adequacy lookup never returns null for ID.
        $list = [
            ['country_code' => 'ID', 'country_name' => 'Indonesia', 'region' => 'ASEAN', 'basis' => 'Domestic — UU PDP applies', 'default_regulation_mismatch' => 1, 'default_sovereign_access_risk' => 1, 'default_admin_sanctions' => 1],
        ];

        // GDPR adequacy — EU/EEA + decisions
        $gdprAdequate = [
            'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
            'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia',
            'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
            'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy', 'LV' => 'Latvia',
            'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands',
            'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
            'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
            'IS' => 'Iceland', 'LI' => 'Liechtenstein', 'NO' => 'Norway',
        ];
        foreach ($gdprAdequate as $code => $name) {
            $list[] = ['country_code' => $code, 'country_name' => $name, 'region' => 'EU/EEA', 'basis' => 'GDPR Art. 45 adequacy'];
        }

        // GDPR adequacy decisions outside EU
        $gdprThirdCountries = [
            ['GB', 'United Kingdom', 'Europe', 'GDPR Art. 45 adequacy'],
            ['CH', 'Switzerland', 'Europe', 'GDPR Art. 45 adequacy'],
            ['JP', 'Japan', 'Asia', 'GDPR Art. 45 adequacy + APPI'],
            ['KR', 'South Korea', 'Asia', 'GDPR Art. 45 adequacy + PIPA'],
            ['NZ', 'New Zealand', 'Oceania', 'GDPR Art. 45 adequacy'],
            ['CA', 'Canada (commercial)', 'North America', 'GDPR adequacy + PIPEDA'],
            ['IL', 'Israel', 'Middle East', 'GDPR Art. 45 adequacy'],
            ['UY', 'Uruguay', 'South America', 'GDPR Art. 45 adequacy'],
            ['AR', 'Argentina', 'South America', 'GDPR Art. 45 adequacy'],
        ];
        foreach ($gdprThirdCountries as [$code, $name, $region, $basis]) {
            $list[] = ['country_code' => $code, 'country_name' => $name, 'region' => $region, 'basis' => $basis];
        }

        return array_map(fn ($r) => array_merge($base, $r), $list);
    }

    /** @return array<int, array<string, mixed>> */
    private function tierComparable(): array
    {
        $base = [
            'tier' => CountryAdequacy::TIER_COMPARABLE,
            'default_regulation_mismatch' => 4,
            'default_sovereign_access_risk' => 3,
            'default_admin_sanctions' => 4,
            'has_pdp_law' => true,
            'has_pdp_authority' => true,
            'recommended_safeguards_required' => true,
            'is_active' => true,
        ];

        $list = [
            ['country_code' => 'SG', 'country_name' => 'Singapore', 'region' => 'ASEAN', 'basis' => 'PDPA 2012 + ASEAN PDP harmonization'],
            ['country_code' => 'MY', 'country_name' => 'Malaysia', 'region' => 'ASEAN', 'basis' => 'PDPA 2010 + ASEAN PDP harmonization'],
            ['country_code' => 'PH', 'country_name' => 'Philippines', 'region' => 'ASEAN', 'basis' => 'Data Privacy Act 2012 + ASEAN'],
            ['country_code' => 'TH', 'country_name' => 'Thailand', 'region' => 'ASEAN', 'basis' => 'PDPA 2019 + ASEAN'],
            ['country_code' => 'VN', 'country_name' => 'Vietnam', 'region' => 'ASEAN', 'basis' => 'Decree 13/2023 + ASEAN'],
            ['country_code' => 'BN', 'country_name' => 'Brunei', 'region' => 'ASEAN', 'basis' => 'PDPO + ASEAN'],
            ['country_code' => 'AU', 'country_name' => 'Australia', 'region' => 'Oceania', 'basis' => 'Privacy Act 1988'],
            ['country_code' => 'HK', 'country_name' => 'Hong Kong', 'region' => 'Asia', 'basis' => 'PDPO'],
            ['country_code' => 'TW', 'country_name' => 'Taiwan', 'region' => 'Asia', 'basis' => 'PDPA'],
            ['country_code' => 'ZA', 'country_name' => 'South Africa', 'region' => 'Africa', 'basis' => 'POPIA'],
        ];

        return array_map(fn ($r) => array_merge($base, $r), $list);
    }

    /** @return array<int, array<string, mixed>> */
    private function tierLimited(): array
    {
        $base = [
            'tier' => CountryAdequacy::TIER_LIMITED,
            'default_regulation_mismatch' => 6,
            'default_sovereign_access_risk' => 6,
            'default_admin_sanctions' => 5,
            'has_pdp_law' => true,
            'has_pdp_authority' => true,
            'recommended_safeguards_required' => true,
            'is_active' => true,
        ];

        $list = [
            ['country_code' => 'US', 'country_name' => 'United States', 'region' => 'North America', 'basis' => 'Sectoral federal + state laws (CCPA, VCDPA, etc.); CLOUD Act sovereign-access risk', 'default_sovereign_access_risk' => 7],
            ['country_code' => 'IN', 'country_name' => 'India', 'region' => 'Asia', 'basis' => 'DPDP Act 2023 — recent, enforcement maturing'],
            ['country_code' => 'BR', 'country_name' => 'Brazil', 'region' => 'South America', 'basis' => 'LGPD'],
            ['country_code' => 'AE', 'country_name' => 'United Arab Emirates', 'region' => 'Middle East', 'basis' => 'PDP Law 2021 (Federal) — DIFC adequate, mainland enforcement varies'],
            ['country_code' => 'TR', 'country_name' => 'Turkey', 'region' => 'Europe/Asia', 'basis' => 'KVKK 2016'],
            ['country_code' => 'SA', 'country_name' => 'Saudi Arabia', 'region' => 'Middle East', 'basis' => 'PDPL 2021'],
            ['country_code' => 'MX', 'country_name' => 'Mexico', 'region' => 'North America', 'basis' => 'LFPDPPP'],
        ];

        return array_map(fn ($r) => array_merge($base, $r), $list);
    }

    /** @return array<int, array<string, mixed>> */
    private function tierNone(): array
    {
        $base = [
            'tier' => CountryAdequacy::TIER_NONE,
            'default_regulation_mismatch' => 9,
            'default_sovereign_access_risk' => 9,
            'default_admin_sanctions' => 7,
            'has_pdp_law' => false,
            'has_pdp_authority' => false,
            'recommended_safeguards_required' => true,
            'is_active' => true,
        ];

        $list = [
            ['country_code' => 'CN', 'country_name' => 'China', 'region' => 'Asia', 'basis' => 'PIPL 2021 exists but data-localization + state-access mandate'],
            ['country_code' => 'RU', 'country_name' => 'Russia', 'region' => 'Europe/Asia', 'basis' => 'Federal Law 152-FZ — strict localization + sovereign access'],
            ['country_code' => 'IR', 'country_name' => 'Iran', 'region' => 'Middle East', 'basis' => 'No comprehensive PDP framework'],
            ['country_code' => 'KP', 'country_name' => 'North Korea', 'region' => 'Asia', 'basis' => 'No PDP framework'],
            ['country_code' => 'BY', 'country_name' => 'Belarus', 'region' => 'Europe', 'basis' => 'Limited PDP enforcement'],
        ];

        return array_map(fn ($r) => array_merge($base, $r), $list);
    }
}
