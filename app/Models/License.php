<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class License extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'license_key', 'package_type', 'license_type', 'status',
        'org_id', 'org_name', 'domain_whitelist', 'ip_log',
        'max_activations', 'activation_count', 'activated_at', 'expires_at',
        'duration_days', 'features', 'notes', 'created_by',
    ];

    protected $casts = [
        'domain_whitelist' => 'array',
        'ip_log' => 'array',
        'features' => 'array',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a unique license key: PRIV-XXXX-XXXX-XXXX-XXXX
     */
    public static function generateKey(): string
    {
        do {
            $key = 'PRIV-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (self::where('license_key', $key)->exists());
        return $key;
    }

    public function isExpired(): bool
    {
        if ($this->license_type === 'perpetual') return false;
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    public function getPackageLabel(): string
    {
        return match ($this->package_type) {
            'basic' => 'Basic (Tanpa AI)',
            'ai' => 'Pro (Dengan AI)',
            'ai_agent' => 'Enterprise (AI Agent)',
            default => $this->package_type,
        };
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function activations()
    {
        return $this->hasMany(LicenseActivation::class);
    }
}
