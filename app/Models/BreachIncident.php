<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BreachIncident extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'incident_code', 'linked_ropa_id', 'linked_ropa_ids', 'title', 'description', 'severity', 'source',
        'case_type', 'containment_template_id',
        'status', 'is_simulation', 'affected_data_types', 'affected_subjects_count',
        'root_cause', 'containment_actions', 'containment_checklist', 'remediation_plan',
        'notification_required', 'notification_deadline', 'notified_komdigi_at',
        'notified_subjects_at', 'notification_template', 'detected_by',
        'incident_commander', 'dpo_id', 'pic_id', 'pic_name', 'detected_at', 'assessed_at',
        'contained_at', 'closed_at', 'timeline_log', 'created_by',
        'custom_raci', 'containment_steps',
    ];

    protected $casts = [
        'is_simulation' => 'boolean', 'notification_required' => 'boolean',
        'affected_data_types' => 'array', 'containment_checklist' => 'array',
        'notification_template' => 'array', 'timeline_log' => 'array',
        'custom_raci' => 'array', 'containment_steps' => 'array',
        'linked_ropa_ids' => 'array',
        'notification_deadline' => 'datetime', 'detected_at' => 'datetime',
        'assessed_at' => 'datetime', 'contained_at' => 'datetime', 'closed_at' => 'datetime',
        'notified_komdigi_at' => 'datetime', 'notified_subjects_at' => 'datetime',
        // PII Encryption — AES-256-CBC
        'pic_name' => EncryptedString::class,
        'description' => EncryptedString::class,
    ];

    protected $appends = ['linked_ropas'];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** Primary (first) RoPA — kept for legacy callers. */
    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    /**
     * Materialized list of linked RoPAs. Uses the new linked_ropa_ids array
     * when populated, else falls back to the legacy single linked_ropa_id.
     * Returns [{id, registration_number, processing_activity}] so the UI
     * can label the multi-select without a second query.
     */
    public function getLinkedRopasAttribute(): array
    {
        $ids = $this->linked_ropa_ids;
        if (empty($ids) && $this->linked_ropa_id) {
            $ids = [$this->linked_ropa_id];
        }
        if (empty($ids) || ! is_array($ids)) {
            return [];
        }

        return Ropa::whereIn('id', $ids)
            ->get(['id', 'registration_number', 'processing_activity'])
            ->toArray();
    }
}
