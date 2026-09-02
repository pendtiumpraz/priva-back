<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penunjukan PPDP (Pejabat/Petugas Pelindungan Data Pribadi) — PP 33/2026
 * Pasal 142-143.
 *
 * `is_mandatory` dan `mandatory_reasons` dihitung dari 3 pemicu Pasal 142(1);
 * `competency_complete` dari 3 aspek kompetensi Pasal 143.
 */
class PpdpAppointment extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const STATUSES = ['active', 'inactive', 'revoked'];

    /** Pemicu wajib menunjuk PPDP — Pasal 142(1) huruf a/b/c. */
    public const TRIGGERS = [
        'trigger_public_service' => 'Pemrosesan Data Pribadi untuk kepentingan pelayanan publik',
        'trigger_large_scale_monitoring' => 'Kegiatan inti memerlukan pemantauan teratur & sistematis atas Data Pribadi dalam skala besar',
        'trigger_large_scale_specific_criminal' => 'Kegiatan inti berupa pemrosesan skala besar atas Data Pribadi spesifik dan/atau data terkait tindak pidana',
    ];

    protected $fillable = [
        'org_id', 'user_id', 'appointee_name', 'appointee_email', 'appointee_phone',
        'appointee_position', 'is_internal', 'sk_number', 'sk_date',
        'appointment_basis', 'scope', 'reporting_line', 'term_start', 'term_end',
        'status', 'trigger_public_service', 'trigger_large_scale_monitoring',
        'trigger_large_scale_specific_criminal', 'competency_professional',
        'competency_legal_knowledge', 'competency_pdp_practice', 'certifications',
        'competency_notes', 'contact_published', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'contact_published' => 'boolean',
        'trigger_public_service' => 'boolean',
        'trigger_large_scale_monitoring' => 'boolean',
        'trigger_large_scale_specific_criminal' => 'boolean',
        'competency_professional' => 'boolean',
        'competency_legal_knowledge' => 'boolean',
        'competency_pdp_practice' => 'boolean',
        'sk_date' => 'date',
        'term_start' => 'date',
        'term_end' => 'date',
    ];

    protected $appends = ['is_mandatory', 'mandatory_reasons', 'competency_complete'];

    /** User platform yang ditunjuk (untuk PPDP internal). Null untuk pihak ketiga. */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Wajib menunjuk PPDP bila salah satu pemicu Pasal 142(1) terpenuhi. */
    public function getIsMandatoryAttribute(): bool
    {
        return $this->trigger_public_service
            || $this->trigger_large_scale_monitoring
            || $this->trigger_large_scale_specific_criminal;
    }

    /** Daftar alasan (label pemicu) yang membuat penunjukan wajib. */
    public function getMandatoryReasonsAttribute(): array
    {
        $reasons = [];
        foreach (self::TRIGGERS as $key => $label) {
            if ($this->{$key}) {
                $reasons[] = $label;
            }
        }

        return $reasons;
    }

    /** Kompetensi Pasal 143 dianggap lengkap bila ketiga aspek terpenuhi. */
    public function getCompetencyCompleteAttribute(): bool
    {
        return $this->competency_professional
            && $this->competency_legal_knowledge
            && $this->competency_pdp_practice;
    }
}
