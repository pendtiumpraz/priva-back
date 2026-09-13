<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kondisi: "di collection point INI, item INI, keadaannya ITU".
 *
 * Tiga baris seperti ini di satu aturan = logika lintas tiga collection point,
 * tanpa angka 2 atau 3 pernah muncul di skema.
 */
class ConsentRuleCondition extends Model
{
    use BelongsToOrg, HasUuids;

    protected $fillable = [
        'org_id', 'rule_id', 'collection_point_id', 'consent_item_id', 'state',
    ];

    /**
     * @return BelongsTo<ConsentRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ConsentRule::class, 'rule_id');
    }

    /**
     * @return BelongsTo<ConsentCollectionPoint, $this>
     */
    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_point_id');
    }

    /**
     * @return BelongsTo<ConsentItem, $this>
     */
    public function consentItem(): BelongsTo
    {
        return $this->belongsTo(ConsentItem::class, 'consent_item_id');
    }
}
