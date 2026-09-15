<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\AssignmentVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property string|null $org_id
 * @property string|null $collection_id
 * @property string|null $name
 * @property string|null $kind
 * @property array<string, mixed>|null $settings
 * @property string|null $webhook_url
 * @property string|null $captcha_provider
 * @property string|null $captcha_site_key
 * @property string|null $consent_rule_set_id
 */
class ConsentCollectionPoint extends Model
{
    use AssignmentVisibility, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'collection_id', 'name', 'kind', 'domain', 'redirect_url',
        'settings', 'webhook_url', 'created_by',
        'assign_group', 'assignees', 'origin_division',
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
        'assignees' => 'array',
        'auth_methods' => 'array',
        'allowed_domains' => 'array',
        'api_keys_last_rotated_at' => 'datetime',
        'captcha_secret' => EncryptedString::class,
        'server_key' => EncryptedString::class,
    ];

    /**
     * Secrets: encrypted at rest, and never serialised.
     *
     * Without this, every path that serialises a collection point shipped both
     * values DECRYPTED: `ConsentLogController::index` eager-loads the relation
     * onto each row (so any `permission:consent,read` caller saw them), and the
     * universal-CRUD `consent` module (`ModuleCrudController:91`) returns the
     * model itself on list and show.
     *
     * `$hidden` only affects toArray()/toJson(). Property reads still work, so
     * `CaptchaVerifier::verifyForCollection` and `AuthenticateConsentApiKey`
     * are unaffected, and `regenerateApiKeys` still reveals the plaintext key
     * once because it returns an explicit array key, not the model.
     */
    protected $hidden = ['server_key', 'captcha_secret'];

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
     * Consent logs (identifiable consent capture) — tabel `consent_logs`,
     * FK `collection_id`. INI sumber data "Records" yang sebenarnya
     * (public capture + CS manual). Berbeda dari relasi records() di atas
     * yang menunjuk tabel legacy `consent_records` (FK collection_point_id)
     * dan biasanya kosong — itu kenapa records_count dulu selalu 0.
     */
    public function logs()
    {
        return $this->hasMany(ConsentLog::class, 'collection_id');
    }

    /**
     * Many-to-many: 1 collection point (banner) bisa terkait banyak RoPA
     * (cookie banner cover analytics + marketing + cross-border transfer activity).
     */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'consent_collection_ropa', 'collection_point_id', 'ropa_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }
}
