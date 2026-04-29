<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiaAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'title', 'description', 'processing_activity', 'linked_ropa_id',
        'purpose_test', 'necessity_test', 'balancing_test',
        'overall_score', 'assessment_result', 'status',
        'wizard_data', 'created_by',
    ];

    protected $casts = [
        'purpose_test' => 'array',
        'necessity_test' => 'array',
        'balancing_test' => 'array',
        'wizard_data' => 'array',
        'overall_score' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
