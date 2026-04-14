<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Webhook extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'url', 'secret', 'events', 'is_active',
        'retry_count', 'timeout_seconds', 'last_triggered_at',
        'total_deliveries', 'failed_deliveries', 'created_by',
    ];

    protected $hidden = [
        'secret',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
        // Credential Encryption — AES-256-CBC
        'secret' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
