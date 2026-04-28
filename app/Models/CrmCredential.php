<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmCredential extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const PROVIDER_HUBSPOT = 'hubspot';

    public const PROVIDER_MAILCHIMP = 'mailchimp';

    public const PROVIDER_SALESFORCE = 'salesforce';

    public const PROVIDER_WEBHOOK = 'webhook';

    public const PROVIDERS = [
        self::PROVIDER_HUBSPOT,
        self::PROVIDER_MAILCHIMP,
        self::PROVIDER_SALESFORCE,
        self::PROVIDER_WEBHOOK,
    ];

    protected $fillable = [
        'org_id', 'provider', 'label', 'is_active',
        'api_key', 'api_secret', 'endpoint_url', 'list_or_object_ref', 'extra_config',
        'last_used_at', 'rotated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'api_key' => EncryptedString::class,
        'api_secret' => EncryptedString::class,
        'extra_config' => 'array',
        'last_used_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    /** Hide secrets when serialized to API responses. */
    protected $hidden = ['api_key', 'api_secret'];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** Convenience: mask in toArray with last 4 chars only. */
    public function toArray(): array
    {
        $arr = parent::toArray();
        $apiKey = $this->getAttributes()['api_key'] ?? null;
        if ($apiKey) {
            try {
                $decrypted = $this->getAttribute('api_key');
                $arr['api_key_masked'] = $decrypted ? '••••'.substr($decrypted, -4) : null;
            } catch (\Throwable $e) {
                $arr['api_key_masked'] = '••••';
            }
        }
        return $arr;
    }
}
