<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorAssessment extends Model
{
    use HasFactory, HasUuids;

    public const SOURCE_DETERMINISTIC = 'deterministic';
    public const SOURCE_AI = 'ai';
    public const SOURCE_IMPORTED = 'imported';

    protected $fillable = [
        'vendor_id',
        'org_id',
        'assessed_by',
        'answers',
        'score',
        'risk_level',
        'recommendations',
        'notes',
        'source',
        'category',
        'score_breakdown',
        'questionnaire_version',
    ];

    protected $casts = [
        'answers' => 'array',
        'recommendations' => 'array',
        'score_breakdown' => 'array',
        'score' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
