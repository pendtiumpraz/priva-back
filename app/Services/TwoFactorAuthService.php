<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP service. Wrapper di atas pragmarx/google2fa untuk:
 *
 *   - generate secret + QR code SVG (untuk authenticator app pertama kali)
 *   - verify TOTP code (6 digit, rotasi tiap 30 detik)
 *   - generate + verify recovery codes (10 backup codes one-time use)
 *   - issue + verify login challenge token (cache-based, 5 menit)
 *
 * State per user di kolom `users`:
 *   two_factor_secret           — encrypted base32 secret (Crypt::encryptString)
 *   two_factor_recovery_codes   — encrypted JSON array
 *   two_factor_confirmed_at     — null = setup incomplete (gak ke-enforce di login)
 *
 * Login challenge: setelah password OK + user punya 2fa_confirmed_at, kita
 * generate UUID, simpan ke cache `2fa_challenge:<uuid>` => userId untuk 5
 * menit, return UUID ke frontend. Frontend prompt code, POST ke
 * /auth/2fa/verify dengan {challenge, code} → service validate code, return
 * full Sanctum token. Pakai cache (bukan DB token) supaya gak bocor ke
 * personal_access_tokens table — challenge bersifat ephemeral.
 */
class TwoFactorAuthService
{
    private const RECOVERY_CODE_COUNT = 10;
    private const RECOVERY_CODE_LENGTH = 10; // chars (XXXXX-XXXXX format)
    private const CHALLENGE_TTL_SECONDS = 300;
    private const CHALLENGE_CACHE_PREFIX = '2fa_challenge:';

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Generate secret baru + simpan ke user (encrypted, BUT belum confirmed
     * yet). User harus call confirm() dengan kode pertama dari authenticator
     * untuk activate.
     *
     * Return: secret plaintext + QR data URI untuk render <img src=...>.
     */
    public function setup(User $user, string $issuer): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => null, // generated saat confirm
            'two_factor_confirmed_at' => null,
        ])->save();

        $otpUrl = $this->google2fa->getQRCodeUrl(
            $issuer,
            $user->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_url' => $otpUrl,
            'qr_svg' => $this->renderQrSvg($otpUrl),
        ];
    }

    /**
     * Confirm setup — verify kode dari authenticator. Kalau valid, generate
     * recovery codes, set confirmed_at, return recovery codes (sekali itu
     * doang plaintext, user WAJIB save).
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret) return null;

        $secret = Crypt::decryptString($user->two_factor_secret);
        if (! $this->google2fa->verifyKey($secret, $code)) {
            return null;
        }

        $recovery = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $recovery[] = $this->generateRecoveryCode();
        }

        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recovery)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recovery;
    }

    /**
     * Disable 2FA — clear semua state. Caller responsibility untuk re-auth
     * (mis. minta password ulang) sebelum panggil ini.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Verify TOTP code OR recovery code. Return true kalau salah satunya
     * valid. Recovery code dipakai sekali — setelah verify, dihapus dari
     * recovery list.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_confirmed_at || ! $user->two_factor_secret) {
            return false;
        }

        $code = trim($code);

        // Coba TOTP dulu (lebih umum)
        $secret = Crypt::decryptString($user->two_factor_secret);
        if ($this->google2fa->verifyKey($secret, $code)) {
            return true;
        }

        // Fallback ke recovery code
        if ($user->two_factor_recovery_codes) {
            $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
            if (is_array($codes)) {
                $idx = array_search($code, $codes, true);
                if ($idx !== false) {
                    // Consume — hapus dari list
                    array_splice($codes, $idx, 1);
                    $user->forceFill([
                        'two_factor_recovery_codes' => Crypt::encryptString(json_encode($codes)),
                    ])->save();
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Regenerate recovery codes. Old codes invalidated.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $recovery = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $recovery[] = $this->generateRecoveryCode();
        }
        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recovery)),
        ])->save();
        return $recovery;
    }

    /**
     * Apakah login user ini perlu 2FA?
     *   - User sudah confirmed 2FA → wajib (mereka opt-in sendiri)
     *   - Atau role-nya ke-enforce 2FA dari setting → wajib (admin force)
     *     [tapi user belum setup → lain cerita: butuh "force-setup" flow,
     *     untuk v1 kita return true dan controller responsible untuk render
     *     setup wizard]
     */
    public function isRequiredFor(User $user): bool
    {
        if ($user->two_factor_confirmed_at) return true;

        $role = $user->role;
        if (! $role) return false;

        $requiredKey = "security.2fa_required_for_{$role}";
        return (bool) config($requiredKey, false);
    }

    /**
     * Issue login challenge — generate UUID, cache (uuid => userId) for 5 min.
     */
    public function issueChallenge(User $user): string
    {
        $challenge = (string) Str::uuid();
        Cache::put(self::CHALLENGE_CACHE_PREFIX.$challenge, $user->id, self::CHALLENGE_TTL_SECONDS);
        return $challenge;
    }

    /**
     * Verify login challenge + code. Return User kalau valid (dan delete
     * challenge dari cache supaya gak bisa dipakai 2x). Null kalau invalid.
     */
    public function verifyChallenge(string $challenge, string $code): ?User
    {
        $key = self::CHALLENGE_CACHE_PREFIX.$challenge;
        $userId = Cache::get($key);
        if (! $userId) return null;

        $user = User::find($userId);
        if (! $user) {
            Cache::forget($key);
            return null;
        }

        if (! $this->verifyCode($user, $code)) {
            // Tidak hapus challenge — biar user bisa retry kode yang bener
            // dalam window TTL. Tapi rate-limit per challenge attempt? Untuk
            // v1 skip, login throttle sudah handle abuse global.
            return null;
        }

        Cache::forget($key);
        return $user;
    }

    /** Hitung sisa recovery codes user. */
    public function recoveryCodesCount(User $user): int
    {
        if (! $user->two_factor_recovery_codes) return 0;
        $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
        return is_array($codes) ? count($codes) : 0;
    }

    private function generateRecoveryCode(): string
    {
        // Format XXXXX-XXXXX (10 chars + dash) — mudah dibaca + di-type ulang
        return strtolower(Str::random(5).'-'.Str::random(5));
    }

    private function renderQrSvg(string $otpUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(280, 1),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);
        return $writer->writeString($otpUrl);
    }
}
