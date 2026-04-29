<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * A registered Postgres/MySQL cluster Privasimu can provision tenant
 * databases into. Managed via the platform-admin UI.
 *
 * `provisioner_password` and `ca_cert` are stored encrypted at rest using
 * Crypt::encryptString. Accessors decrypt on read; mutators encrypt on
 * write so callers always work with plaintext strings.
 *
 * Lives in the landlord DB. Not tenant-scoped.
 */
class DatabasePool extends Model
{
    use HasUuids, SoftDeletes, LandlordPinned;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DRAINING = 'draining';   // accept no new tenants, keep serving existing

    public const ENGINE_PGSQL = 'pgsql';
    public const ENGINE_MYSQL = 'mysql';

    protected $fillable = [
        'name', 'description',
        'engine', 'host', 'port',
        'provisioner_user', 'provisioner_password',
        'sslmode', 'ca_cert',
        'region', 'status',
        'max_tenants', 'current_tenants_count',
        'metadata', 'created_by',
    ];

    protected $casts = [
        'port' => 'integer',
        'max_tenants' => 'integer',
        'current_tenants_count' => 'integer',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'provisioner_password', 'ca_cert',  // never serialize secrets
    ];

    protected function provisionerPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : $this->safeDecrypt($value),
            set: fn ($value) => $value === null || $value === '' ? null : Crypt::encryptString($value),
        );
    }

    protected function caCert(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : $this->safeDecrypt($value),
            set: fn ($value) => $value === null || $value === '' ? null : Crypt::encryptString($value),
        );
    }

    private function safeDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function organizations()
    {
        return $this->hasMany(Organization::class, 'db_pool_id');
    }

    public function isAcceptingTenants(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) return false;
        if ($this->max_tenants === null) return true;
        return $this->current_tenants_count < $this->max_tenants;
    }
}
