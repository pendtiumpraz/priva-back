<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SecurityAlert extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'rule_code', 'severity', 'title', 'description',
        'module', 'record_id', 'status', 'acknowledged_by',
        'acknowledged_at', 'resolved_by', 'resolved_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
