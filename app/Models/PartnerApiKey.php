<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class PartnerApiKey extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'key_prefix', 'key_hash', 'permissions',
        'allowed_ips', 'environment', 'rate_limit_per_minute',
        'last_used_at', 'total_requests', 'expires_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'allowed_ips' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    /**
     * Generate a new API key pair.
     * Returns: ['key' => 'pk_live_xxxxxx', 'model' => PartnerApiKey]
     */
    public static function generateKey(array $attributes): array
    {
        $env = $attributes['environment'] ?? 'live';
        $rawKey = 'pk_' . $env . '_' . Str::random(40);
        $prefix = substr($rawKey, 0, 12) . '...' . substr($rawKey, -4);

        $model = static::create(array_merge($attributes, [
            'key_prefix' => $prefix,
            'key_hash' => Hash::make($rawKey),
        ]));

        return ['key' => $rawKey, 'model' => $model];
    }

    /**
     * Verify a raw API key against this model.
     */
    public function verifyKey(string $rawKey): bool
    {
        return Hash::check($rawKey, $this->key_hash);
    }

    /**
     * Check if key has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?? [];
        return in_array('*', $perms) || in_array($permission, $perms);
    }

    /**
     * Record a request.
     */
    public function recordRequest(): void
    {
        $this->increment('total_requests');
        $this->update(['last_used_at' => now()]);
    }

    // Relationships
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs()
    {
        return $this->hasMany(ApiRequestLog::class, 'api_key_id');
    }
}
