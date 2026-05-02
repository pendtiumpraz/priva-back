<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Person Scan hit row (masked + optionally encrypted).
 *
 * `masked_row` is always populated and what the UI renders. `encrypted_row`
 * is OnPrem-only — Crypt::encryptString(json_encode(raw_row)) — decrypted
 * via the Reveal action which requires the granular `data_discovery,reveal`
 * permission and is audit-logged.
 *
 * Tenant-scoped via org_id; no BelongsToOrg auto-scope (queue worker context).
 *
 * @property string $id
 * @property string $org_id
 * @property string $scan_plan_id
 * @property string $plan_system_id
 * @property string $information_system_id
 * @property string $table_name
 * @property string $confidence
 * @property array $matched_columns
 * @property int $match_count
 * @property array $row_pks
 * @property array $masked_row
 * @property string|null $encrypted_row
 * @property bool $revealed
 * @property string|null $revealed_by
 * @property Carbon|null $revealed_at
 */
class DataDiscoveryScanResult extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'data_discovery_scan_results';

    protected $fillable = [
        'org_id',
        'scan_plan_id',
        'plan_system_id',
        'information_system_id',
        'table_name',
        'confidence',
        'matched_columns',
        'match_count',
        'row_pks',
        'masked_row',
        'encrypted_row',
        'revealed',
        'revealed_by',
        'revealed_at',
    ];

    protected $casts = [
        'matched_columns' => 'array',
        'row_pks' => 'array',
        'masked_row' => 'array',
        'match_count' => 'integer',
        'revealed' => 'boolean',
        'revealed_at' => 'datetime',
    ];

    /**
     * Hide the encrypted blob in default JSON serialization — frontend never
     * sees ciphertext, only the masked_row. Reveal endpoint emits decrypted
     * payload separately, not via toArray().
     */
    protected $hidden = ['encrypted_row'];

    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where($this->getTable().'.org_id', $orgId);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DataDiscoveryScanPlan::class, 'scan_plan_id');
    }

    public function planSystem(): BelongsTo
    {
        return $this->belongsTo(DataDiscoveryScanPlanSystem::class, 'plan_system_id');
    }

    public function informationSystem(): BelongsTo
    {
        return $this->belongsTo(InformationSystem::class, 'information_system_id');
    }
}
