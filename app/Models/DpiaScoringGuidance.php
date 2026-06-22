<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Override "Panduan Nilai Penilaian Risiko" DPIA per tenant. Satu baris per org.
 * payload = { dampak: [...], probabilitas: [...], kontrol: [...], penanganan: [...] }.
 */
class DpiaScoringGuidance extends Model
{
    use BelongsToOrg, HasUuids;

    protected $table = 'dpia_scoring_guidance';

    protected $fillable = ['org_id', 'payload', 'updated_by'];

    protected $casts = ['payload' => 'array'];
}
