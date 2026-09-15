<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use App\Support\BreachScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BreachIncident extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'incident_code', 'linked_ropa_id', 'linked_ropa_ids', 'linked_vendor_ids', 'title', 'description', 'severity', 'source',
        'case_type', 'containment_template_id',
        'status', 'is_simulation', 'affected_data_types', 'affected_subjects_count',
        'root_cause', 'containment_actions', 'containment_checklist', 'remediation_plan',
        'notification_required', 'notification_deadline', 'notified_komdigi_at',
        'notified_subjects_at', 'notification_template', 'detected_by',
        'public_notification_required', 'public_notification_grounds', 'notified_public_at',
        'incident_commander', 'dpo_id', 'pic_id', 'pic_name', 'detected_at', 'assessed_at',
        'contained_at', 'closed_at', 'timeline_log', 'created_by',
        'custom_raci', 'containment_steps',
        'affected_systems', 'affected_data_categories',
    ];

    protected $casts = [
        'is_simulation' => 'boolean', 'notification_required' => 'boolean',
        'affected_data_types' => 'array', 'containment_checklist' => 'array',
        'notification_template' => 'array', 'timeline_log' => 'array',
        'custom_raci' => 'array', 'containment_steps' => 'array',
        'linked_ropa_ids' => 'array',
        'linked_vendor_ids' => 'array',
        // Pilihan terstruktur dari Data Discovery: sistem → tabel → kolom PII.
        'affected_systems' => 'array',
        'affected_data_categories' => 'array',
        'notification_deadline' => 'datetime', 'detected_at' => 'datetime',
        'assessed_at' => 'datetime', 'contained_at' => 'datetime', 'closed_at' => 'datetime',
        'notified_komdigi_at' => 'datetime', 'notified_subjects_at' => 'datetime',
        'public_notification_required' => 'boolean', 'public_notification_grounds' => 'array',
        'notified_public_at' => 'datetime',
        // PII Encryption — AES-256-CBC
        'pic_name' => EncryptedString::class,
        'description' => EncryptedString::class,
    ];

    protected $appends = ['linked_ropas', 'linked_third_parties'];

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
     * Keterlihatan per divisi — diturunkan dari RoPA terdampak dan pihak ketiga
     * terlibat, bukan dari kolom sendiri. Aturannya tinggal di BreachScope.
     */
    public function scopeVisibleTo($query, $user)
    {
        BreachScope::terapkan($query, $user, (string) ($user->org_id ?? ''));

        return $query;
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

    /**
     * Pihak ketiga yang DIPASTIKAN terlibat — dipilih penanggung jawab insiden,
     * bukan hasil penelusuran. Dugaan lewat RoPA disajikan terpisah oleh
     * BreachThirdPartyController::suggested() supaya keduanya tidak tertukar.
     */
    public function getLinkedThirdPartiesAttribute(): array
    {
        $ids = $this->linked_vendor_ids;
        if (empty($ids) || ! is_array($ids)) {
            return [];
        }

        return Vendor::whereIn('id', $ids)
            ->get(['id', 'name', 'country', 'risk_level'])
            ->toArray();
    }
}
