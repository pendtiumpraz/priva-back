<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * EncryptedString Cast
 * 
 * Auto-encrypts on write, auto-decrypts on read.
 * Fallback: if decryption fails (data was stored plaintext before migration),
 * returns the raw value as-is. This ensures zero-downtime migration.
 * 
 * Cipher: AES-256-CBC (Laravel default via APP_KEY)
 */
class EncryptedString implements CastsAttributes
{
    /**
     * Decrypt the value when reading from database.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Fallback: data lama yang belum terenkripsi
            // Ini memungkinkan migrasi bertahap tanpa downtime
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

        return Crypt::encryptString($value);
    }
}
