<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class License extends Model
{
    use HasUuids, SoftDeletes;

    /** Pinned to landlord — license records belong to platform, not tenant. */
    protected $connection = 'landlord';

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
    // SIGNED PAYLOAD — Asymmetric Tamper-proof license data (RS256 JWT)
    // =============================================

    /**
     * Verify and decode a signed RS256 JWT payload from the License Manager.
     * Returns the payload data if valid, null if tampered/invalid.
     */
    public static function verifySignedPayload(?string $jwt): ?array
    {
        if (!$jwt) return null;

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;

        try {
            $payloadStr = base64_decode(strtr($parts[1], '-_', '+/'));
            $signature = base64_decode(strtr($parts[2], '-_', '+/'));
            
            $publicKeyB64 = config('services.license.public_key');
            if (!$publicKeyB64) {
                \Log::error('LICENSE_PUBLIC_KEY is not set in config/services/environment');
                return null;
            }

            // Decode the base64 public key back to standard PEM text format
            $pem = base64_decode($publicKeyB64);
            
            // For RS256, the data signed is the first two parts of the JWT separated by a dot
            $dataToSign = $parts[0] . '.' . $parts[1];
            
            // Verify RSA-SHA256 signature
            $valid = openssl_verify($dataToSign, $signature, $pem, OPENSSL_ALGO_SHA256);
            
            if ($valid === 1) {
                $payloadData = json_decode($payloadStr, true);
                // The Node.js License Manager nested the actual license data under 'payload'
                if ($payloadData && isset($payloadData['payload'])) {
                     return $payloadData['payload'];
                }
            }
            
            \Log::warning('License signed payload TAMPERED or INVALID', [
                'reason' => 'Asymmetric signature verification failed',
            ]);
            return null;
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
        
        // SECURE STRICT MODE: If token verify fails or missing, pretend it's expired immediately to prevent bypass
        return \Carbon\Carbon::now()->subDay();
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
        // SECURE STRICT MODE: Never trust the DB package_type if signature fails. Demote to basic.
        return 'basic';
    }

    /**
     * Check if the signed payload is valid (not tampered).
     */
    public function isSignatureValid(): bool
    {
        if (!$this->signed_payload) return false; // STRICT: ALL licenses MUST have a signature now
        return self::verifySignedPayload($this->signed_payload) !== null;
    }

    // =============================================
    // STATUS CHECKS — Now tamper-proof
    // =============================================

    public function isExpired(): bool
    {
        // STRICT: If no payload or tampered signature → treat as expired immediately
        if (!$this->signed_payload || !$this->isSignatureValid()) {
            return true;
        }

        $trustedType = $this->getTrustedPackageType();
        if ($this->license_type === 'perpetual') return false;

        $trustedExpiry = $this->getTrustedExpiresAt();
        return $trustedExpiry && $trustedExpiry->isPast();
    }

    public function isActive(): bool
    {
        // STRICT: If no payload or tampered → not active
        if (!$this->signed_payload || !$this->isSignatureValid()) {
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

