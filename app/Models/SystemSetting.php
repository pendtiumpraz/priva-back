<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide singleton configuration.
 *
 * Read on boot by SettingsServiceProvider and cached to
 * bootstrap/cache/system_settings.json (5min TTL). DO NOT call
 * SystemSetting::get() during request hot paths — go through config('...')
 * which is already populated by the provider.
 *
 * @property string $key
 * @property mixed  $value         JSON-cast (string|int|bool|array)
 * @property bool   $is_encrypted  When true, $value is a Crypt::encryptString() string at rest
 * @property string $section       infrastructure|redis|ai|mail|aws|deployment
 * @property string|null $updated_by
 */
class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
        'section',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
        'is_encrypted' => 'boolean',
    ];

    /**
     * Fetch a single setting value. Auto-decrypts if is_encrypted.
     *
     * Prefer config('redis.host') etc. in app code — this helper exists for
     * the provider boot path and admin UIs. Returns $default on miss.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);
        if (! $row) {
            return $default;
        }

        $value = $row->value;

        // JSON cast unwraps scalars stored as ["foo"] back to "foo" if we used
        // the Eloquent cast — but we store whatever was given, so handle both.
        if ($row->is_encrypted && is_string($value) && $value !== '') {
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable $e) {
                // Corrupted ciphertext or APP_KEY rotated without re-encrypt.
                // Fail closed — return default rather than leaking encrypted blob.
                return $default;
            }
        }

        return $value ?? $default;
    }
}
