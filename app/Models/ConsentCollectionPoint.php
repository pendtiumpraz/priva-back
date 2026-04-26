<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConsentCollectionPoint extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'collection_id', 'name', 'kind', 'domain', 'redirect_url',
        'settings', 'webhook_url', 'created_by',
        'embed_token', 'client_key', 'server_key', 'auth_methods', 'allowed_domains',
        'display_mode', 'display_frequency', 'audience', 'locale',
        'captcha_provider', 'captcha_site_key', 'captcha_secret',
        'api_keys_last_rotated_at',
    ];

    public const KIND_COOKIE = 'cookie_banner';

    public const KIND_APP = 'app_consent';

    public const KINDS = [self::KIND_COOKIE, self::KIND_APP];

    protected $casts = [
        'settings' => 'array',
        'auth_methods' => 'array',
        'allowed_domains' => 'array',
        'api_keys_last_rotated_at' => 'datetime',
        'captcha_secret' => EncryptedString::class,
        'server_key' => EncryptedString::class,
    ];

    protected static function booted(): void
    {
        // Auto-generate embed_token on create (one-time, never rotates unless explicit regenerate)
        static::creating(function (self $cp) {
            if (empty($cp->embed_token)) {
                $cp->embed_token = self::generateUniqueToken();
            }
        });

        // Cache invalidation on save/delete (existing behavior preserved)
        $bust = function (self $c) {
            Cache::forget('consent:config:'.sha1($c->collection_id));
            Cache::forget('consent:config:'.sha1($c->id));
            Cache::forget('consent:collection:'.sha1($c->collection_id));
            Cache::forget('consent:collection:'.sha1($c->id));
            if ($c->embed_token) {
                Cache::forget('consent:config:'.sha1($c->embed_token));
                Cache::forget('consent:cp_by_embed_token:'.sha1($c->embed_token));
            }
            if ($c->client_key) {
                Cache::forget('consent:cp_by_client_key:'.sha1($c->client_key));
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('embed_token', $token)->exists());

        return $token;
    }

    public static function generateApiKeyPair(): array
    {
        do {
            $clientKey = 'pk_consent_'.Str::random(32);
        } while (self::where('client_key', $clientKey)->exists());
        $serverKey = 'sk_consent_'.Str::random(48);

        return [$clientKey, $serverKey];
    }

    public function isWidgetEnabled(): bool
    {
        return ($this->auth_methods['widget'] ?? true) === true;
    }

    public function isApiKeyEnabled(): bool
    {
        return ($this->auth_methods['api_key'] ?? false) === true && ! empty($this->client_key);
    }

    public function isCookieBanner(): bool
    {
        return $this->kind === self::KIND_COOKIE;
    }

    public function isAppConsent(): bool
    {
        return $this->kind === self::KIND_APP;
    }

    /**
     * Defaults applied per kind. Klien bisa override individual fields, tapi
     * preset di sini = recommended starting config.
     */
    public static function presetForKind(string $kind): array
    {
        if ($kind === self::KIND_APP) {
            return [
                'audience' => 'logged_in_only',
                'display_mode' => 'modal_center',
                'display_frequency' => 'once',
            ];
        }

        // Cookie banner default (UU PDP + GDPR-friendly)
        return [
            'audience' => 'anonymous_only',
            'display_mode' => 'banner_bottom',
            'display_frequency' => 'once',
        ];
    }

    public function bustConsentCache(): void
    {
        Cache::forget('consent:config:'.sha1($this->collection_id));
        Cache::forget('consent:config:'.sha1($this->id));
        Cache::forget('consent:collection:'.sha1($this->collection_id));
        Cache::forget('consent:collection:'.sha1($this->id));
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function items()
    {
        return $this->hasMany(ConsentItem::class, 'collection_point_id');
    }

    public function records()
    {
        return $this->hasMany(ConsentRecord::class, 'collection_point_id');
    }

    /**
     * Many-to-many: 1 collection point (banner) bisa terkait banyak ROPA
     * (cookie banner cover analytics + marketing + cross-border transfer activity).
     */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'consent_collection_ropa', 'collection_point_id', 'ropa_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }
}
