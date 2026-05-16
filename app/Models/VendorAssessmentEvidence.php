<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 2 — Per-question evidence file.
 *
 * Vendor / pihak ketiga upload bukti untuk pertanyaan yang punya
 * `requires_evidence_upload=true`. Setiap file = 1 row.
 *
 * Lifecycle:
 *  - vendor upload via public token endpoint → uploaded_by_token=true
 *  - admin tenant upload (override / supplement) → uploaded_by_user_id terisi
 *  - kalau upload ulang untuk pertanyaan sama, row lama di-set is_active=false
 *    (kept untuk audit) dan row baru is_active=true
 */
class VendorAssessmentEvidence extends Model
{
    use HasUuids, BelongsToOrg, SoftDeletes;

    protected $table = 'vendor_assessment_evidence';

    protected $fillable = [
        'org_id',
        'assessment_id',
        'question_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by_user_id',
        'uploaded_by_token',
        'uploaded_ip',
        'is_active',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_by_token' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function assessment()
    {
        return $this->belongsTo(VendorAssessment::class, 'assessment_id');
    }

    public function question()
    {
        return $this->belongsTo(VendorQuestionnaire::class, 'question_id');
    }
}
