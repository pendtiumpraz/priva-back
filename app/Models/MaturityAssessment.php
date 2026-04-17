<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaturityAssessment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'title', 'version', 'dimensions', 'overall_level', 'overall_score',
        'recommendations', 'status', 'created_by',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'recommendations' => 'array',
        'overall_level' => 'integer',
        'overall_score' => 'decimal:2',
    ];

    public const DIMENSIONS = ['governance', 'process', 'technology', 'people', 'compliance'];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
