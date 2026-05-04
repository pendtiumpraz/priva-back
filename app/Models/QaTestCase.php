<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QaTestCase extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'module', 'feature', 'interaction', 'title', 'description',
        'expected_behavior', 'applicable_roles', 'license_packages',
        'is_built_in', 'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'applicable_roles' => 'array',
        'license_packages' => 'array',
        'is_built_in' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
