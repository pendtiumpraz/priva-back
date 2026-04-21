<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SecurityAlert extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'rule_code', 'type', 'severity', 'kind', 'title', 'description',
        'module', 'record_id', 'recipient_id', 'recipient_role',
        'read_at', 'priority', 'action_url',
        'status', 'acknowledged_by', 'acknowledged_at',
        'resolved_by', 'resolved_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'read_at' => 'datetime',
        'priority' => 'integer',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
