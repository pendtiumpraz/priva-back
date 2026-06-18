<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Holding Compliance Assessment — Per-question evidence file.
 *
 * Mirror pola TPRM VendorAssessmentEvidence: 1:N (banyak bukti per pertanyaan).
 * Pihak yang dinilai upload via public token (uploaded_by_token=true). Upload
 * ulang TIDAK overwrite — yang lama is_active=false, yang baru is_active=true.
 * Analisis AI dilakukan di reviewer dashboard, bukan di public page.
 */
class HoldingAssessmentEvidence extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $table = 'holding_assessment_evidence';

    protected $fillable = [
        'org_id',
        'instance_id',
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

    public function instance()
    {
        return $this->belongsTo(HoldingAssessmentInstance::class, 'instance_id');
    }
}
