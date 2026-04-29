<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Materialized finding from the posture engine. Each row is an
 * actionable ticket with severity + owner + SLA + status. Lifecycle:
 *
 *   open → in_progress → resolved
 *               ↓
 *         accepted_risk (DPO writes a justification, finding stays
 *                        but doesn't drag posture down anymore)
 *               ↓
 *         dismissed     (false-positive, not a real issue)
 *
 * Findings are auto-materialized after each scan + after each
 * upstream record change (RoPA risk_level=high create, breach detected,
 * etc). Same problem won't duplicate — source_key is unique per org.
 */
class PostureFinding extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ACCEPTED_RISK = 'accepted_risk';
    public const STATUS_DISMISSED = 'dismissed';

    public const ALL_STATUSES = [
        self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED,
        self::STATUS_ACCEPTED_RISK, self::STATUS_DISMISSED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Terbuka',
        self::STATUS_IN_PROGRESS => 'Dikerjakan',
        self::STATUS_RESOLVED => 'Selesai',
        self::STATUS_ACCEPTED_RISK => 'Risiko Diterima',
        self::STATUS_DISMISSED => 'Diabaikan',
    ];

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    public const ALL_SEVERITIES = [
        self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW,
    ];

    /** SLA window in days per severity — drives sla_due_at on creation. */
    public const SLA_DAYS = [
        self::SEVERITY_CRITICAL => 3,
        self::SEVERITY_HIGH => 14,
        self::SEVERITY_MEDIUM => 30,
        self::SEVERITY_LOW => 90,
    ];

    protected $fillable = [
        'org_id',
        'source_pillar', 'source_key', 'source_type', 'source_id', 'source_detail',
        'severity', 'title', 'description', 'regulation_ref', 'metadata',
        'status', 'assigned_to', 'sla_due_at',
        'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_by', 'resolution_notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sla_due_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->sla_due_at && $this->sla_due_at->isPast();
    }
}
