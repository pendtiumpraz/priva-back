<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 4 — Monitoring schedule per vendor.
 *
 * 1 vendor → 0 atau 1 schedule aktif (is_active=true). Edit frequency
 * = soft-delete row lama + create row baru.
 */
class VendorMonitoring extends Model
{
    use HasUuids, BelongsToOrg, SoftDeletes;

    public const FREQUENCY_QUARTERLY = 3;
    public const FREQUENCY_SEMI_ANNUAL = 6;
    public const FREQUENCY_ANNUAL = 12;

    protected $fillable = [
        'org_id',
        'vendor_id',
        'frequency_months',
        'next_due_at',
        'last_completed_at',
        'assigned_user_id',
        'created_by',
        'is_active',
        'notes',
        'reviews_count',
    ];

    protected $casts = [
        'frequency_months' => 'integer',
        'next_due_at' => 'datetime',
        'last_completed_at' => 'datetime',
        'is_active' => 'boolean',
        'reviews_count' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function reviews()
    {
        return $this->hasMany(VendorMonitoringReview::class, 'monitoring_id')
            ->orderByDesc('reviewed_at');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Derive status runtime: upcoming | due | overdue.
     * - overdue: next_due_at < now (- 1 hari grace)
     * - due: next_due_at dalam 7 hari ke depan
     * - upcoming: lebih dari 7 hari lagi
     * - unscheduled: next_due_at null
     */
    public function getDeriveStatusAttribute(): string
    {
        if (! $this->next_due_at) {
            return 'unscheduled';
        }
        $now = now();
        if ($this->next_due_at->lt($now)) {
            return 'overdue';
        }
        if ($this->next_due_at->lt($now->copy()->addDays(7))) {
            return 'due';
        }
        return 'upcoming';
    }
}
