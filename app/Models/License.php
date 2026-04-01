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
        'signed_payload',
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

    // =============================================
    // SIGNED PAYLOAD — Tamper-proof license data
    // =============================================

    /**
     * Get the HMAC secret key from env.
     * This MUST match the key used by the License Manager.
     */
    private static function getSigningKey(): string
    {
        return env('LICENSE_SIGNING_KEY', env('APP_KEY', 'fallback-insecure-key'));
    }

    /**
     * Create a signed payload containing all critical license data.
     * Returns a base64-encoded JSON string with embedded HMAC signature.
     */
    public static function createSignedPayload(array $data): string
    {
        $payload = [
            'license_key'   => $data['license_key'],
            'package_type'  => $data['package_type'],
            'license_type'  => $data['license_type'] ?? 'saas',
            'expires_at'    => $data['expires_at'] ?? null,
            'features'      => $data['features'] ?? [],
            'org_id'        => $data['org_id'] ?? null,
            'max_activations' => $data['max_activations'] ?? 1,
            'issued_at'     => now()->toISOString(),
        ];

        // Sort keys for consistent hashing
        ksort($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $payloadJson, self::getSigningKey());

        return base64_encode(json_encode([
            'payload' => $payload,
            'signature' => $signature,
        ]));
    }

    /**
     * Verify and decode a signed payload.
     * Returns the payload data if valid, null if tampered/invalid.
     */
    public static function verifySignedPayload(?string $signedPayload): ?array
    {
        if (!$signedPayload) return null;

        try {
            $decoded = json_decode(base64_decode($signedPayload), true);
            if (!$decoded || !isset($decoded['payload']) || !isset($decoded['signature'])) {
                return null;
            }

            $payload = $decoded['payload'];
            $signature = $decoded['signature'];

            // Recreate signature from payload
            ksort($payload);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $expectedSignature = hash_hmac('sha256', $payloadJson, self::getSigningKey());

            // Constant-time comparison to prevent timing attacks
            if (!hash_equals($expectedSignature, $signature)) {
                \Log::warning('License signed payload TAMPERED', [
                    'license_key' => $payload['license_key'] ?? 'unknown',
                ]);
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            \Log::error('License signed payload decode error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the trusted expiry date from signed payload.
     * Falls back to DB column only if no signed payload exists (legacy).
     */
    public function getTrustedExpiresAt(): ?\Carbon\Carbon
    {
        $verified = self::verifySignedPayload($this->signed_payload);
        if ($verified && isset($verified['expires_at']) && $verified['expires_at']) {
            return \Carbon\Carbon::parse($verified['expires_at']);
        }
        // Legacy fallback (unsigned — for old licenses before this feature)
        return $this->expires_at;
    }

    /**
     * Get the trusted package type from signed payload.
     */
    public function getTrustedPackageType(): string
    {
        $verified = self::verifySignedPayload($this->signed_payload);
        if ($verified && isset($verified['package_type'])) {
            return $verified['package_type'];
        }
        return $this->package_type;
    }

    /**
     * Check if the signed payload is valid (not tampered).
     */
    public function isSignatureValid(): bool
    {
        if (!$this->signed_payload) return true; // Legacy: no signature = OK
        return self::verifySignedPayload($this->signed_payload) !== null;
    }

    // =============================================
    // STATUS CHECKS — Now tamper-proof
    // =============================================

    public function isExpired(): bool
    {
        // If signature is invalid → treat as expired (tampered!)
        if ($this->signed_payload && !$this->isSignatureValid()) {
            return true;
        }

        $trustedType = $this->getTrustedPackageType();
        if ($this->license_type === 'perpetual') return false;

        $trustedExpiry = $this->getTrustedExpiresAt();
        return $trustedExpiry && $trustedExpiry->isPast();
    }

    public function isActive(): bool
    {
        // If tampered → not active
        if ($this->signed_payload && !$this->isSignatureValid()) {
            return false;
        }
        return $this->status === 'active' && !$this->isExpired();
    }

    public function getPackageLabel(): string
    {
        $pkg = $this->getTrustedPackageType();
        return match ($pkg) {
            'basic' => 'Basic (Tanpa AI)',
            'ai' => 'Pro (Dengan AI)',
            'ai_agent' => 'Enterprise (AI Agent)',
            default => $pkg,
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

