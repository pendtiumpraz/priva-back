<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak satu keputusan.
 *
 * Menyimpan `states` — keadaan consent SAAT ITU — terlihat mubazir karena
 * consent_logs masih ada. Tidak: log bisa bertambah sesudahnya, sehingga
 * menghitung ulang keadaan hari ini tidak akan pernah menjelaskan kenapa
 * pengiriman bulan lalu terjadi. Itu justru pertanyaan yang diajukan auditor.
 */
class ConsentRuleDecision extends Model
{
    use BelongsToOrg, HasUuids;

    protected $fillable = [
        'org_id', 'rule_set_id', 'collection_point_id', 'context',
        'subject_identifier', 'blocked',
        'segments', 'matched', 'states', 'decided_at',
    ];

    protected $casts = [
        'blocked' => 'boolean',
        'segments' => 'array',
        'matched' => 'array',
        'states' => 'array',
        'decided_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ConsentRuleSet, $this>
     */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ConsentRuleSet::class, 'rule_set_id');
    }
}
