<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Workflow row for tenant-initiated infrastructure changes that require
 * superadmin review (assigning DB pool, switching to BYODB, changing
 * storage pool, etc).
 *
 * Lifecycle: pending → (approved → executing → executed) | denied | failed
 *
 * `payload` shape varies by request_type — validated in the controller
 * using request-type-specific rules.
 */
class TenantChangeRequest extends Model
{
    use HasUuids, LandlordPinned;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';

    public const TYPE_DB_ASSIGN_POOL = 'db_assign_pool';
    public const TYPE_DB_CHANGE_POOL = 'db_change_pool';
    public const TYPE_DB_SWITCH_TO_BYODB = 'db_switch_to_byodb';
    public const TYPE_STORAGE_ASSIGN_POOL = 'storage_assign_pool';
    public const TYPE_STORAGE_CHANGE_POOL = 'storage_change_pool';
    public const TYPE_STORAGE_SWITCH_TO_BYOS = 'storage_switch_to_byos';
    public const TYPE_RESET_TO_SHARED = 'reset_to_shared';

    public const ALL_TYPES = [
        self::TYPE_DB_ASSIGN_POOL,
        self::TYPE_DB_CHANGE_POOL,
        self::TYPE_DB_SWITCH_TO_BYODB,
        self::TYPE_STORAGE_ASSIGN_POOL,
        self::TYPE_STORAGE_CHANGE_POOL,
        self::TYPE_STORAGE_SWITCH_TO_BYOS,
        self::TYPE_RESET_TO_SHARED,
    ];

    protected $fillable = [
        'org_id', 'requested_by',
        'request_type', 'payload', 'reason',
        'status',
        'reviewed_by', 'reviewed_at', 'review_notes',
        'executed_at', 'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'reviewed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isReviewed(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_DENIED, self::STATUS_EXECUTED, self::STATUS_FAILED], true);
    }
}
