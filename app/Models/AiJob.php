<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Async AI job ledger.
 *
 * One row per dispatched ProcessAiJob. Tenant-scoped by org_id — every query
 * MUST filter org_id (CLAUDE.md multi-tenant invariant). NOT using the
 * BelongsToOrg trait because queue workers run without CurrentOrgContext set,
 * and ProcessAiJob loads the row by id then re-establishes scope from the
 * row's stored org_id; auto-scoping would silently break worker reads.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $type        autofill|analyzer|summary|deep_scan
 * @property string|null $module
 * @property string|null $subject_id
 * @property string $label
 * @property string $status      pending|running|done|failed|cancelled
 * @property int    $progress    0-100
 * @property array  $payload
 * @property array|null $result
 * @property string|null $error
 * @property int    $credits_used
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class AiJob extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];
    public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'org_id',
        'user_id',
        'type',
        'module',
        'subject_id',
        'label',
        'status',
        'progress',
        'payload',
        'result',
        'error',
        'credits_used',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'progress' => 'integer',
        'credits_used' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Scope to a single tenant. Always required when querying outside a
     * controller that already has tenant context.
     */
    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    /**
     * Jobs that are still pending or running (used for dedup + concurrency
     * quota checks).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
