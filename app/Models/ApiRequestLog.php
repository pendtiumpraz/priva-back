<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_key_id', 'org_id', 'method', 'endpoint',
        'status_code', 'response_time_ms', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function apiKey()
    {
        return $this->belongsTo(PartnerApiKey::class, 'api_key_id');
    }
}
