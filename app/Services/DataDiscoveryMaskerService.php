<?php

namespace App\Services;

/**
 * Single source of truth for PII masking in Person Scan results.
 *
 * Used by:
 *   - DataDiscoveryAppExecutor (OnPrem) — mask raw rows from PDO before insert
 *   - DataDiscoveryScanController upload (SaaS) — validate that the CSV uploaded
 *     by the admin actually matches the expected mask pattern (defense-in-depth
 *     against admins pasting raw values into the CSV)
 *   - DataDiscoveryScanGeneratorService — mask identifiers on the plan record
 *     itself so the row is not a PII risk
 *
 * Classification keys mirror those used by InformationSystem.scan_results
 * (`tables[].columns[].classification`). Anything not recognized is treated
 * as non-PII and returned clear.
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §4 backend masker table.
 */
class DataDiscoveryMaskerService
{
    /**
     * Classifications that are PII and must be masked. Anything else is clear.
     */
    public const PII_CLASSIFICATIONS = [
        'email',
        'phone', 'mobile', 'phone_number',
        'nik', 'national_id', 'identity_number',
        'name', 'full_name', 'first_name', 'last_name',
        'dob', 'birth_date', 'date_of_birth',
        'address',
        'account_number', 'bank_account',
        'npwp', 'tax_id',
        'credit_card', 'card_number',
        'passport', 'passport_number',
    ];

    /**
     * Mask a single value according to its classification. Returns the value
     * unchanged for non-PII or empty input.
     */
    public static function mask(mixed $value, ?string $classification): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $value = (string) $value;
        $classification = strtolower((string) $classification);

        return match ($classification) {
            'email' => self::maskEmail($value),
            'phone', 'mobile', 'phone_number' => self::maskPhone($value),
            'nik', 'national_id', 'identity_number' => self::maskNik($value),
            'name', 'full_name', 'first_name', 'last_name' => self::maskName($value),
            'dob', 'birth_date', 'date_of_birth' => self::maskDob($value),
            'address' => self::maskAddress($value),
            'account_number', 'bank_account' => self::maskAccountNumber($value),
            'npwp', 'tax_id' => self::maskAccountNumber($value),
            'credit_card', 'card_number' => self::maskCreditCard($value),
            'passport', 'passport_number' => self::maskAccountNumber($value),
            default => $value, // non-PII or unknown classification → clear
        };
    }

    /**
     * Mask a whole row. `$columnClassifications` is a map of column name to
     * classification (typically lifted from InformationSystem.scan_results).
     */
    public static function maskRow(array $row, array $columnClassifications): array
    {
        $masked = [];
        foreach ($row as $col => $val) {
            $cls = $columnClassifications[$col] ?? null;
            $masked[$col] = self::mask($val, $cls);
        }

        return $masked;
    }

    /**
     * Validate a value claims to be masked. Used by the SaaS upload endpoint
     * to refuse a CSV whose admin populated raw values instead of the mask.
     * Returns true when the input either matches the mask pattern OR the
     * classification is non-PII (so anything goes).
     */
    public static function validateMasked(mixed $value, ?string $classification): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $value = (string) $value;
        $classification = strtolower((string) $classification);

        if (! in_array($classification, self::PII_CLASSIFICATIONS, true)) {
            return true;
        }

        // Mask outputs all contain at least one '*' — if the admin pasted raw
        // PII the validation fails. Tighten per-classification regex below.
        if (! str_contains($value, '*')) {
            return false;
        }

        return match ($classification) {
            'email' => (bool) preg_match('/^.{1,3}\*+@.+$/', $value),
            'phone', 'mobile', 'phone_number' => (bool) preg_match('/^\d{0,4}\*+\d{0,3}$/', $value),
            'nik', 'national_id', 'identity_number' => (bool) preg_match('/^\d{0,4}\*+\d{0,4}$/', $value),
            'name', 'full_name', 'first_name', 'last_name' => (bool) preg_match('/\*/', $value),
            'dob', 'birth_date', 'date_of_birth' => (bool) preg_match('/^\d{4}-\*+-\*+$/', $value),
            'address' => str_contains($value, '*'),
            default => true,
        };
    }

    // =========================================================================
    // Per-classification implementations
    // =========================================================================

    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $localMasked = mb_substr($local, 0, 1).'***';

        // domain: keep first char, mask up to TLD
        $dot = strrpos($domain, '.');
        if ($dot === false) {
            return $localMasked.'@'.mb_substr($domain, 0, 1).'***';
        }
        $domainHead = mb_substr($domain, 0, 1).'***';
        $tld = substr($domain, $dot); // includes the leading dot

        return $localMasked.'@'.$domainHead.$tld;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($digits === '') {
            return '***';
        }
        $len = strlen($digits);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        if ($len <= 7) {
            // 4 visible + rest masked
            return substr($digits, 0, 4).str_repeat('*', $len - 4);
        }
        $head = substr($digits, 0, 4);
        $tail = substr($digits, -3);
        $stars = str_repeat('*', max(4, $len - 7));

        return $head.$stars.$tail;
    }

    private static function maskNik(string $nik): string
    {
        $digits = preg_replace('/[^0-9]/', '', $nik) ?? '';
        $len = strlen($digits);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        $head = substr($digits, 0, 4);
        $tail = substr($digits, -4);
        $stars = str_repeat('*', $len - 8);

        return $head.$stars.$tail;
    }

    private static function maskName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return '***';
        }
        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            $w = $parts[0];

            return mb_substr($w, 0, 1).str_repeat('*', max(2, mb_strlen($w) - 1));
        }
        $first = $parts[0];
        $lastInitial = mb_substr(end($parts), 0, 1);

        return $first.' '.$lastInitial.'***';
    }

    private static function maskDob(string $dob): string
    {
        // Accept YYYY-MM-DD, YYYY/MM/DD, or any leading 4-digit year
        if (preg_match('/^(\d{4})/', $dob, $m)) {
            return $m[1].'-**-**';
        }

        return '****-**-**';
    }

    private static function maskAddress(string $addr): string
    {
        $parts = preg_split('/\s+/', trim($addr)) ?: [];
        if (count($parts) === 0) {
            return '***';
        }
        $masked = [];
        foreach ($parts as $i => $w) {
            if ($i === 0 && mb_strlen($w) > 0) {
                // Keep first token (e.g. "Jl.")
                $masked[] = $w;
            } elseif (preg_match('/^\d+$/', $w)) {
                $masked[] = str_repeat('*', mb_strlen($w));
            } else {
                $masked[] = mb_substr($w, 0, 1).'***';
            }
        }

        return implode(' ', $masked);
    }

    private static function maskAccountNumber(string $acc): string
    {
        $digits = preg_replace('/[^0-9]/', '', $acc) ?? '';
        $len = strlen($digits);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        $head = substr($digits, 0, 3);
        $tail = substr($digits, -3);
        $stars = str_repeat('*', $len - 6);

        return $head.$stars.$tail;
    }

    private static function maskCreditCard(string $cc): string
    {
        $digits = preg_replace('/[^0-9]/', '', $cc) ?? '';
        $len = strlen($digits);
        if ($len < 8) {
            return str_repeat('*', $len);
        }
        $head = substr($digits, 0, 4);
        $tail = substr($digits, -4);
        $stars = str_repeat('*', $len - 8);

        return $head.$stars.$tail;
    }
}
