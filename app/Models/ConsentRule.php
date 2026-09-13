<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu aturan: "JIKA <semua kondisi terpenuhi> MAKA <tindakan>".
 *
 * Kondisi di dalam satu aturan selalu di-AND. Untuk ATAU, tenant menulis
 * aturan kedua. Ini batasan yang disengaja — lihat migrasi
 * 2026_09_13_000002_create_consent_rule_engine.
 */
class ConsentRule extends Model
{
    use BelongsToOrg, HasUuids;

    /** Jangan kirim sama sekali. Terminal: menghentikan evaluasi. */
    public const ACTION_BLOCK = 'block';

    /** Segmen ini terlarang bagi subjek tersebut. */
    public const ACTION_EXCLUDE = 'exclude_segment';

    /** Segmen ini boleh. */
    public const ACTION_INCLUDE = 'include_segment';

    public const ACTIONS = [self::ACTION_BLOCK, self::ACTION_EXCLUDE, self::ACTION_INCLUDE];

    protected $fillable = [
        'org_id', 'rule_set_id', 'sequence', 'name', 'action', 'segment', 'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<ConsentRuleSet, $this>
     */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ConsentRuleSet::class, 'rule_set_id');
    }

    /**
     * @return HasMany<ConsentRuleCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(ConsentRuleCondition::class, 'rule_id');
    }
}
