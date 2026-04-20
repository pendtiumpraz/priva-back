<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DecryptorProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'system_id', 'org_id', 'name', 'algorithm',
        'encrypted_key', 'key_fingerprint', 'columns',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'columns' => 'array',
        'is_active' => 'boolean',
    ];

    // Never expose the wrapped key blob in API responses — clients only need
    // metadata (algorithm, fingerprint, scope).
    protected $hidden = ['encrypted_key'];
}
