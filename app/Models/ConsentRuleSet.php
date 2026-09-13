<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu set aturan consent milik tenant.
 *
 * Set adalah unit yang dievaluasi: aturan di dalamnya berurutan, dan
 * `default_action` menutup celah ketika tak ada yang cocok.
 */
class ConsentRuleSet extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const ACTION_BLOCK = 'block';

    public const ACTION_SEND = 'send';

    public const DEFAULT_ACTIONS = [self::ACTION_BLOCK, self::ACTION_SEND];

    protected $fillable = [
        'org_id', 'name', 'description', 'default_action', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Urutan aturan adalah data, bukan kebetulan urutan baris — karena itu
     * relasi ini SELALU mengurutkan. `id` jadi pemecah seri supaya dua aturan
     * bersequence sama tetap dievaluasi dengan urutan yang sama di tiap
     * pemanggilan; keputusan kepatuhan tidak boleh berubah-ubah sendiri.
     *
     * @return HasMany<ConsentRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ConsentRule::class, 'rule_set_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ConsentRuleDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ConsentRuleDecision::class, 'rule_set_id');
    }
}
