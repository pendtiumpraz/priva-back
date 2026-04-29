<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level country adequacy reference. Tells the TIA wizard +
 * CBDT inventory whether a destination is "adequate / comparable /
 * limited / none" under UU PDP Pasal 56 working interpretation.
 *
 * Pinned to landlord because the same list applies to every tenant —
 * adequacy is a regulatory concept, not a tenant preference.
 */
class CountryAdequacy extends Model
{
    use HasUuids, LandlordPinned;

    public const TIER_ADEQUATE = 'adequate';
    public const TIER_COMPARABLE = 'comparable';
    public const TIER_LIMITED = 'limited';
    public const TIER_NONE = 'none';

    public const TIER_LABELS = [
        self::TIER_ADEQUATE => 'Adequate (Setara)',
        self::TIER_COMPARABLE => 'Comparable (Sebanding)',
        self::TIER_LIMITED => 'Limited (Terbatas)',
        self::TIER_NONE => 'None (Tidak Ada)',
    ];

    protected $fillable = [
        'country_code', 'country_name', 'region',
        'tier', 'basis', 'notes',
        'default_regulation_mismatch', 'default_sovereign_access_risk', 'default_admin_sanctions',
        'has_pdp_law', 'has_pdp_authority', 'recommended_safeguards_required',
        'is_active',
    ];

    protected $casts = [
        'default_regulation_mismatch' => 'integer',
        'default_sovereign_access_risk' => 'integer',
        'default_admin_sanctions' => 'integer',
        'has_pdp_law' => 'boolean',
        'has_pdp_authority' => 'boolean',
        'recommended_safeguards_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Resolve a free-form country string to an adequacy record.
     * Tolerates ISO code, full name, or messy mixed-case input.
     */
    public static function resolve(?string $countryStringOrCode): ?self
    {
        if (!$countryStringOrCode) return null;
        $needle = strtolower(trim($countryStringOrCode));

        return static::query()
            ->where('is_active', true)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(country_code) = ?', [$needle])
                    ->orWhereRaw('LOWER(country_name) = ?', [$needle]);
            })
            ->first();
    }
}
