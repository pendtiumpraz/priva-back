<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NotificationSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'record_type', 'record_id', 'rule_key',
        'next_fire_at', 'last_fired_at', 'enabled', 'metadata',
    ];

    protected $casts = [
        'next_fire_at' => 'datetime',
        'last_fired_at' => 'datetime',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];
}
