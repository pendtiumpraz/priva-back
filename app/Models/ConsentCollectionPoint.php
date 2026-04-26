<?php
namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConsentCollectionPoint extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'collection_id', 'name', 'domain', 'redirect_url',
        'settings', 'webhook_url', 'created_by',
        'embed_token', 'client_key', 'server_key', 'auth_methods', 'allowed_domains',
        'display_mode', 'display_frequency', 'audience',
        'captcha_provider', 'captcha_site_key', 'captcha_secret',
        'api_keys_last_rotated_at',
    ];

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
            Cache::forget('consent:config:' . sha1($c->collection_id));
            Cache::forget('consent:config:' . sha1($c->id));
            Cache::forget('consent:collection:' . sha1($c->collection_id));
            Cache::forget('consent:collection:' . sha1($c->id));
            if ($c->embed_token) {
                Cache::forget('consent:config:' . sha1($c->embed_token));
                Cache::forget('consent:cp_by_embed_token:' . sha1($c->embed_token));
            }
            if ($c->client_key) {
                Cache::forget('consent:cp_by_client_key:' . sha1($c->client_key));
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
            $clientKey = 'pk_consent_' . Str::random(32);
        } while (self::where('client_key', $clientKey)->exists());
        $serverKey = 'sk_consent_' . Str::random(48);
        return [$clientKey, $serverKey];
    }

    public function isWidgetEnabled(): bool
    {
        return ($this->auth_methods['widget'] ?? true) === true;
    }

    public function isApiKeyEnabled(): bool
    {
        return ($this->auth_methods['api_key'] ?? false) === true && !empty($this->client_key);
    }

    public function bustConsentCache(): void
    {
        Cache::forget('consent:config:' . sha1($this->collection_id));
        Cache::forget('consent:config:' . sha1($this->id));
        Cache::forget('consent:collection:' . sha1($this->collection_id));
        Cache::forget('consent:collection:' . sha1($this->id));
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
    public function items()
    {
        return $this->hasMany(ConsentItem::class , 'collection_point_id');
    }
    public function records()
    {
        return $this->hasMany(ConsentRecord::class , 'collection_point_id');
    }
}
