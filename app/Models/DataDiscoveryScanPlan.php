<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Person Scan plan ledger.
 *
 * One row per "Scan Person Across Apps" run. Tenant-scoped via org_id but
 * does NOT use the BelongsToOrg trait (same rationale as AiJob): queue
 * workers and SaaS upload handlers re-establish scope from the row itself,
 * so an auto global scope keyed off CurrentOrgContext would silently break
 * worker reads. Always call ::forOrg($orgId) when querying.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $label
 * @property array $identifiers masked {email,name,nik?,phone?,dob?}
 * @property array|null $identifier_hashes
 * @property string $status generated|executing|awaiting_upload|completed|failed|expired
 * @property int $total_systems
 * @property int $total_tables
 * @property int $skipped_tables
 * @property int $total_hits
 * @property int $progress
 * @property string|null $parent_ai_job_id
 * @property string|null $saas_pack_path
 * @property Carbon|null $expires_at
 */
class DataDiscoveryScanPlan extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'data_discovery_scan_plans';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_AWAITING_UPLOAD = 'awaiting_upload';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'org_id',
        'user_id',
        'label',
        'identifiers',
        'identifier_hashes',
        'status',
        'total_systems',
        'total_tables',
        'skipped_tables',
        'total_hits',
        'progress',
        'parent_ai_job_id',
        'saas_pack_path',
        'expires_at',
    ];

    protected $casts = [
        'identifiers' => 'array',
        'identifier_hashes' => 'array',
        'total_systems' => 'integer',
        'total_tables' => 'integer',
        'skipped_tables' => 'integer',
        'total_hits' => 'integer',
        'progress' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where($this->getTable().'.org_id', $orgId);
    }

    public function planSystems(): HasMany
    {
        return $this->hasMany(DataDiscoveryScanPlanSystem::class, 'scan_plan_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(DataDiscoveryScanResult::class, 'scan_plan_id');
    }

    public function parentJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'parent_ai_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
