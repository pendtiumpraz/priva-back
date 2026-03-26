<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ConsentRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'consent_item_id', 'collection_point_id', 'subject_identifier',
        'subject_name', 'channel', 'is_granted', 'ip_address', 'user_agent',
        'proof', 'granted_at', 'revoked_at', 'revoke_reason', 'recorded_by',
    ];

    protected $casts = [
        'is_granted' => 'boolean', 'granted_at' => 'datetime', 'revoked_at' => 'datetime',
    ];
}
