<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RopaTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'industry', 'activity_code', 'description',
        'wizard_data', 'is_system', 'org_id', 'is_active', 'usage_count',
    ];

    protected $casts = [
        'wizard_data' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];
}
