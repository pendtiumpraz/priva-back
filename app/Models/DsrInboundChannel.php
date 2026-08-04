<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Kanal penerimaan permohonan subjek data di luar formulir publik.
 */
class DsrInboundChannel extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const TYPES = ['webhook', 'imap'];

    protected $fillable = [
        'org_id', 'app_id', 'name', 'type', 'inbound_token', 'config',
        'is_active', 'last_polled_at', 'total_received', 'total_rejected', 'created_by',
    ];

    protected $hidden = ['config', 'inbound_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_polled_at' => 'datetime',
        // Memuat kata sandi kotak surat.
        'config' => EncryptedString::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $channel) {
            if ($channel->type === 'webhook' && empty($channel->inbound_token)) {
                $channel->inbound_token = self::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::withoutGlobalScope('org')->where('inbound_token', $token)->exists());

        return $token;
    }

    /** @return array<string, mixed> */
    public function configArray(): array
    {
        $raw = $this->config;

        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }
}
