<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GapComparison extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'title', 'regulation_code', 'assessment_ids', 
        'chart_data', 'system_analysis', 'ai_analysis', 'created_by'
    ];

    protected $casts = [
        'assessment_ids' => 'array',
        'chart_data' => 'array',
    ];
}
