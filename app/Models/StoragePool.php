<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * A registered S3-compatible / GCS storage backend Privasimu can route
 * tenant uploads through. Generalization of the prior single-row
 * `app_settings.platform.storage.*` config.
 *
 * Like DatabasePool: secrets encrypted at rest, decrypted via accessors.
 * Lives in the landlord DB.
 */
class StoragePool extends Model
{
    use HasUuids, SoftDeletes, LandlordPinned;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const DRIVER_S3 = 's3';
    public const DRIVER_MINIO = 'minio';
    public const DRIVER_DO_SPACES = 'do_spaces';
    public const DRIVER_GCS = 'gcs';

    protected $fillable = [
        'name', 'description',
        'driver', 'endpoint', 'region', 'bucket',
        'access_key', 'secret_key',
        'use_path_style_endpoint',
        'is_default', 'status',
        'metadata', 'created_by',
    ];

    protected $casts = [
        'use_path_style_endpoint' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'access_key', 'secret_key',
    ];

    protected function accessKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : $this->safeDecrypt($value),
            set: fn ($value) => $value === null || $value === '' ? null : Crypt::encryptString($value),
        );
    }

    protected function secretKey(): Attribute
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
        return $this->hasMany(Organization::class, 'storage_pool_id');
    }

    /**
     * Build a Laravel filesystem disk config array from this pool.
     * Consumed by TenantStorageService when the pool resolves as the
     * effective backend for a tenant's request.
     */
    public function toDiskConfig(): array
    {
        return match ($this->driver) {
            self::DRIVER_S3, self::DRIVER_MINIO, self::DRIVER_DO_SPACES => [
                'driver' => 's3',
                'key'    => $this->access_key,
                'secret' => $this->secret_key,
                'region' => $this->region ?? 'us-east-1',
                'bucket' => $this->bucket,
                'endpoint' => $this->endpoint,
                'use_path_style_endpoint' => $this->use_path_style_endpoint || $this->driver !== self::DRIVER_S3,
                'throw' => false,
            ],
            self::DRIVER_GCS => [
                'driver' => 'gcs',
                'project_id' => $this->metadata['project_id'] ?? null,
                'key_file' => $this->access_key,   // re-purposed: holds JSON key for GCS
                'bucket' => $this->bucket,
                'throw' => false,
            ],
            default => [],
        };
    }
}
