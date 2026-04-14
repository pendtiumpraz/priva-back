<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * EncryptedString Cast
 * 
 * Auto-encrypts on write, auto-decrypts on read.
 * Fully defensive: if encryption is unavailable (bad APP_KEY, missing extension),
 * gracefully falls back to plaintext — the app NEVER crashes.
 * 
 * Cipher: AES-256-CBC (Laravel default via APP_KEY)
 */
class EncryptedString implements CastsAttributes
{
    /**
     * Check if encryption is available on this server.
     */
    private function canEncrypt(): bool
    {
        try {
            $key = config('app.key');
            if (empty($key)) return false;

            // Validate key format: must be base64:XXXXX with correct length
            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);
                if ($decoded === false || strlen($decoded) !== 32) return false;
            } else {
                if (strlen($key) !== 32) return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Decrypt the value when reading from database.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        if (!$this->canEncrypt()) {
            return $value; // Encryption unavailable, return as-is
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // Fallback: data lama plaintext atau encryption error
            return $value;
        }
    }

    /**
     * Encrypt the value when writing to database.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        if (!$this->canEncrypt()) {
            return $value; // Encryption unavailable, store plaintext
        }

        try {
            return \Illuminate\Support\Facades\Crypt::encryptString($value);
        } catch (\Throwable $e) {
            return $value; // Fallback: store plaintext if encrypt fails
        }
    }
}
