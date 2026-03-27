<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCreditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'user_id', 'action_type', 'credits_used',
        'status', 'module', 'record_id', 'metadata', 'error_message',
    ];

    protected $casts = [
        'credits_used' => 'float',
        'metadata' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
