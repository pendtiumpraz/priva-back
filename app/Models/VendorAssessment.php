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
        'assessment_token',
        'token_expires_at',
        'token_consumed_at',
        'status',
        'submitted_at',
        'submitted_ip',
        'submitted_user_agent',
    ];

    protected $casts = [
        'answers' => 'array',
        'recommendations' => 'array',
        'score_breakdown' => 'array',
        'score' => 'integer',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
        'submitted_at' => 'datetime',
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
