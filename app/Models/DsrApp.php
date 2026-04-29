<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * DSR App — registered klien external app yang embed DSR widget.
 * 1 tenant punya N apps. Setiap app punya embed_token unik.
 */
class DsrApp extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'name', 'app_code', 'description', 'embed_token',
        'client_key', 'server_key', 'auth_methods', 'api_keys_last_rotated_at',
        'allowed_domains', 'default_information_system_ids',
        'default_assignee_user_id', 'webhook_url', 'branding',
        'requires_nda_for_access', 'nda_template_doc_id', 'nda_signing_method',
        'captcha_provider', 'captcha_site_key', 'captcha_secret',
        'locale',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'default_information_system_ids' => 'array',
        'branding' => 'array',
        'auth_methods' => 'array',
        'api_keys_last_rotated_at' => 'datetime',
        'is_active' => 'boolean',
        'requires_nda_for_access' => 'boolean',
        'captcha_secret' => EncryptedString::class,
        'server_key' => EncryptedString::class,
    ];

    /**
     * Generate a fresh API key pair. Returns [client_key, server_key_plain].
     * Caller MUST display server_key plaintext exactly once — it's encrypted at rest.
     */
    public static function generateApiKeyPair(): array
    {
        do {
            $clientKey = 'pk_live_'.Str::random(32);
        } while (self::where('client_key', $clientKey)->exists());
        $serverKey = 'sk_live_'.Str::random(48);

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

    protected static function booted(): void
    {
        static::creating(function (DsrApp $app) {
            if (empty($app->embed_token)) {
                $app->embed_token = self::generateUniqueToken();
            }
            if (empty($app->app_code)) {
                $app->app_code = self::deriveAppCode($app->name);
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function defaultAssignee()
    {
        return $this->belongsTo(User::class, 'default_assignee_user_id');
    }

    public function dsrRequests()
    {
        return $this->hasMany(DsrRequest::class, 'app_id');
    }

    public function ndaTemplate()
    {
        return $this->belongsTo(Document::class, 'nda_template_doc_id');
    }

    /**
     * Resolve InformationSystem records dari default_information_system_ids JSON.
     */
    public function defaultInformationSystems()
    {
        $ids = $this->default_information_system_ids ?? [];
        if (empty($ids)) {
            return collect();
        }

        return InformationSystem::whereIn('id', $ids)->where('org_id', $this->org_id)->get();
    }

    /**
     * Generate unique 64-char embed token (cryptographically secure).
     */
    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('embed_token', $token)->exists());

        return $token;
    }

    /**
     * Derive 3-6 char app code from name (e.g., "Mobile Banking App" → "MBA").
     */
    public static function deriveAppCode(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $code = '';
        foreach ($words as $w) {
            $w = preg_replace('/[^A-Za-z0-9]/', '', $w);
            if ($w !== '') {
                $code .= mb_strtoupper(mb_substr($w, 0, 1));
            }
            if (mb_strlen($code) >= 3) {
                break;
            }
        }
        if (mb_strlen($code) < 3) {
            $code = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
        }

        return $code ?: 'APP';
    }
}
