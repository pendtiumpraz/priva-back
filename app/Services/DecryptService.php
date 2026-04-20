<?php

namespace App\Services;

use App\Models\DecryptorProfile;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * DecryptService — handles tenant database decryption keys safely.
 *
 * Architecture:
 *   - Each InformationSystem can have one or more DecryptorProfile rows.
 *   - The tenant's raw encryption key is *wrapped* (AES-256-GCM) with a
 *     platform master key from env TENANT_KEY_WRAP (fallback: derived from
 *     APP_KEY + fixed context), and only the wrapped form touches the DB.
 *   - unwrap() is the only way to access the raw key, and the result is
 *     held in a PHP-level variable — never logged, never serialized back.
 *   - Adapters take the unwrapped key + a ciphertext and return plaintext.
 *
 * Supported target algorithms (what the tenant's DB uses to encrypt values):
 *   - laravel_crypt     — Laravel's Crypt::encryptString format (AES-256-CBC
 *                         + HMAC, base64(json({iv, value, mac, tag}))). Key
 *                         is the tenant Laravel APP_KEY.
 *   - aes_256_gcm       — Raw AES-GCM. Layout: base64(iv(12) || tag(16) || cipher).
 *   - aes_256_cbc       — Raw AES-CBC with PKCS7. Layout: base64(iv(16) || cipher).
 *   - sodium_secretbox  — libsodium secretbox. Layout: base64(nonce(24) || cipher).
 */
class DecryptService
{
    public const ALGORITHMS = [
        'laravel_crypt',
        'aes_256_gcm',
        'aes_256_cbc',
        'sodium_secretbox',
    ];

    // -----------------------------------------------------------
    // Platform master key (for wrapping tenant keys at rest)
    // -----------------------------------------------------------
    private static function masterKey(): string
    {
        $raw = env('TENANT_KEY_WRAP');
        if ($raw) {
            return self::decodeKey($raw);
        }
        // Fallback: derive from APP_KEY + fixed context. NOTE: a dedicated
        // TENANT_KEY_WRAP is strongly recommended in production so rotating
        // APP_KEY doesn't invalidate all wrapped tenant keys.
        $appKey = config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }
        return hash_hmac('sha256', 'privasimu::tenant_key_wrap::v1', $appKey, true);
    }

    private static function decodeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }
        if (ctype_xdigit($key) && strlen($key) === 64) {
            return hex2bin($key);
        }
        // Raw string — pad / hash to 32 bytes.
        return strlen($key) === 32 ? $key : hash('sha256', $key, true);
    }

    public static function wrap(string $plaintextKey): string
    {
        $master = self::masterKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintextKey, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Failed to wrap tenant key');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function unwrap(string $wrapped): string
    {
        $master = self::masterKey();
        $raw = base64_decode($wrapped, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid wrapped key payload');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plaintext = openssl_decrypt($cipher, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Unwrap failed — master key mismatch or tampered blob');
        }
        return $plaintext;
    }

    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    // -----------------------------------------------------------
    // Profile lifecycle
    // -----------------------------------------------------------
    public static function createProfile(array $data): DecryptorProfile
    {
        self::assertAlgorithm($data['algorithm'] ?? '');
        $rawKey = $data['key'] ?? null;
        if (!$rawKey) throw new \InvalidArgumentException('key is required');

        $wrapped = self::wrap($rawKey);
        $fp = self::fingerprint($rawKey);

        return DecryptorProfile::create([
            'system_id' => $data['system_id'],
            'org_id' => $data['org_id'],
            'name' => $data['name'] ?? 'Default',
            'algorithm' => $data['algorithm'],
            'encrypted_key' => $wrapped,
            'key_fingerprint' => $fp,
            'columns' => $data['columns'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    public static function updateProfile(DecryptorProfile $profile, array $data): DecryptorProfile
    {
        $update = array_intersect_key($data, array_flip(['name', 'algorithm', 'columns', 'is_active']));
        if (isset($update['algorithm'])) self::assertAlgorithm($update['algorithm']);

        if (!empty($data['key'])) {
            $update['encrypted_key'] = self::wrap($data['key']);
            $update['key_fingerprint'] = self::fingerprint($data['key']);
        }
        $profile->update($update);
        return $profile->fresh();
    }

    private static function assertAlgorithm(string $alg): void
    {
        if (!in_array($alg, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException("Algoritma '{$alg}' tidak didukung. Valid: " . implode(', ', self::ALGORITHMS));
        }
    }

    // -----------------------------------------------------------
    // Decrypt one value using a profile
    // -----------------------------------------------------------
    public static function decryptValueWithProfile(DecryptorProfile $profile, string $ciphertext): string
    {
        $key = self::unwrap($profile->encrypted_key);
        try {
            return match ($profile->algorithm) {
                'laravel_crypt'    => self::decryptLaravelCrypt($key, $ciphertext),
                'aes_256_gcm'      => self::decryptAesGcm($key, $ciphertext),
                'aes_256_cbc'      => self::decryptAesCbc($key, $ciphertext),
                'sodium_secretbox' => self::decryptSodium($key, $ciphertext),
                default            => throw new \RuntimeException("Algoritma tidak dikenali: {$profile->algorithm}"),
            };
        } finally {
            // Best-effort memory scrub. PHP strings aren't truly erasable, but
            // overwriting helps reduce exposure in memory dumps.
            $key = str_repeat("\0", strlen($key));
            unset($key);
        }
    }

    // -----------------------------------------------------------
    // Adapter implementations
    // -----------------------------------------------------------
    private static function decryptLaravelCrypt(string $appKey, string $payload): string
    {
        // Tenant APP_KEY may come as base64:... or raw. Support both.
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;
        if (strlen($raw) !== 32) {
            throw new \RuntimeException('Laravel APP_KEY harus 32-byte (base64:... dari key:generate)');
        }
        $enc = new Encrypter($raw, 'AES-256-CBC');
        return $enc->decryptString($payload);
    }

    private static function decryptAesGcm(string $key, string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('AES-GCM: payload base64 invalid atau terlalu pendek');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plaintext = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('AES-GCM decrypt gagal (key mismatch atau payload tamper)');
        }
        return $plaintext;
    }

    private static function decryptAesCbc(string $key, string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            throw new \RuntimeException('AES-CBC: payload base64 invalid atau terlalu pendek');
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plaintext = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException('AES-CBC decrypt gagal (key mismatch atau padding rusak)');
        }
        return $plaintext;
    }

    private static function decryptSodium(string $key, string $payload): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \RuntimeException('ext-sodium tidak tersedia di server ini');
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 25) {
            throw new \RuntimeException('Sodium: payload base64 invalid');
        }
        $nonce = substr($raw, 0, 24);
        $cipher = substr($raw, 24);
        $keyBytes = strlen($key) === 32 ? $key : hash('sha256', $key, true);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $keyBytes);
        if ($plaintext === false) {
            throw new \RuntimeException('Sodium secretbox decrypt gagal');
        }
        return $plaintext;
    }

    /**
     * Self-test: verify a profile can decrypt a known sample ciphertext.
     * Useful on profile create — admin provides one row of ciphertext
     * they already know the plaintext of, and we confirm the key works
     * before saving.
     */
    public static function test(DecryptorProfile $profile, string $sampleCiphertext): array
    {
        try {
            $plain = self::decryptValueWithProfile($profile, $sampleCiphertext);
            return [
                'ok' => true,
                'preview' => strlen($plain) > 40 ? substr($plain, 0, 37) . '…' : $plain,
            ];
        } catch (\Throwable $e) {
            Log::info('DecryptService::test failed', ['algorithm' => $profile->algorithm, 'msg' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
