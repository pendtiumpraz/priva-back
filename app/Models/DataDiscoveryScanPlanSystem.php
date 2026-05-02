<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Per-app SQL bundle for one scan plan.
 *
 * One row per InformationSystem chosen by the strategy matrix. `table_queries`
 * is the canonical SQL bundle (positional `?` placeholders) that the OnPrem
 * executor runs and the SaaS pack ZIP serializes. Multi-tenant scoped via
 * org_id; no BelongsToOrg auto-scope (queue worker context).
 *
 * @property string $id
 * @property string $org_id
 * @property string $scan_plan_id
 * @property string $information_system_id
 * @property string $app_name
 * @property array $table_queries
 * @property string $status pending|running|done|failed|skipped
 * @property int $hit_count
 * @property string|null $child_ai_job_id
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class DataDiscoveryScanPlanSystem extends Model
{
    use HasUuids;

    protected $table = 'data_discovery_scan_plan_systems';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'org_id',
        'scan_plan_id',
        'information_system_id',
        'app_name',
        'table_queries',
        'status',
        'hit_count',
        'child_ai_job_id',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'table_queries' => 'array',
        'hit_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where($this->getTable().'.org_id', $orgId);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DataDiscoveryScanPlan::class, 'scan_plan_id');
    }

    public function informationSystem(): BelongsTo
    {
        return $this->belongsTo(InformationSystem::class, 'information_system_id');
    }

    public function childJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'child_ai_job_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(DataDiscoveryScanResult::class, 'plan_system_id');
    }
}
