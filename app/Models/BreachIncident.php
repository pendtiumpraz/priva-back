<?php
namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class BreachIncident extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'incident_code', 'linked_ropa_id', 'title', 'description', 'severity', 'source',
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
        'notification_deadline' => 'datetime', 'detected_at' => 'datetime',
        'assessed_at' => 'datetime', 'contained_at' => 'datetime', 'closed_at' => 'datetime',
        'notified_komdigi_at' => 'datetime', 'notified_subjects_at' => 'datetime',
        // PII Encryption — AES-256-CBC
        'pic_name' => EncryptedString::class,
        'description' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
