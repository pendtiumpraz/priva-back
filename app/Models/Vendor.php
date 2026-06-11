<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'type',
        'category',                  // Phase 2 — drives questionnaire bank
        'country',
        'contact_name',
        'contact_email',
        'departemen_kontak',
        'bidang',
        'jenis_entitas',
        'website',
        'privacy_policy_url',
        'description',
        'dpa_status',
        'dpa_signed_at',
        'dpa_expires_at',
        'risk_score',
        'risk_level',
        'last_assessed_at',
        'next_assessment_due_at',    // Phase 2 — re-assessment cadence
        'data_shared',
        'services_provided',
        'documents',
        // TPRM Pre-Assessment — PDP scope gate
        'pdp_scope_status',
        'scope_decided_at',
        'scope_decided_by',
        'scope_justification',
        'scope_overridden',
        'scope_approved_by',
        'scope_approved_at',
    ];

    protected $casts = [
        'dpa_signed_at' => 'date',
        'dpa_expires_at' => 'date',
        'last_assessed_at' => 'date',
        'next_assessment_due_at' => 'date',
        'data_shared' => 'array',
        'services_provided' => 'array',
        'documents' => 'array',
        'bidang' => 'array',
        'risk_score' => 'integer',
        // PII Encryption — AES-256-CBC
        'contact_name' => EncryptedString::class,
        'contact_email' => EncryptedString::class,
        // TPRM Pre-Assessment — PDP scope gate
        'scope_overridden' => 'boolean',
        'scope_decided_at' => 'datetime',
        'scope_approved_at' => 'datetime',
    ];

    // PDP scope gate states.
    public const SCOPE_UNSCREENED = 'unscreened';

    public const SCOPE_IN = 'in_scope';

    public const SCOPE_OUT_PENDING = 'out_of_scope_pending';

    public const SCOPE_OUT = 'out_of_scope';

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function assessments()
    {
        return $this->hasMany(VendorAssessment::class, 'vendor_id')->orderBy('created_at', 'desc');
    }

    public function getLatestAssessment()
    {
        return $this->assessments()->first();
    }

    public function preAssessments()
    {
        return $this->hasMany(VendorPreAssessment::class, 'vendor_id')->orderBy('created_at', 'desc');
    }

    /** Latest (non-trashed) pre-assessment row for this vendor. */
    public function latestPreAssessment()
    {
        return $this->preAssessments()->first();
    }
}
