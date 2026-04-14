<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;

class TenantSso extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'org_id', 'provider', 'client_id', 'client_secret', 'tenant_id', 'custom_domain', 'is_active'
    ];

    protected $hidden = [
        'client_secret'
    ];

    protected $casts = [
        // Credential Encryption — AES-256-CBC
        'client_secret' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
