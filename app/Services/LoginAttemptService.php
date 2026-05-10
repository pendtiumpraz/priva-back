<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Login lockout per akun, persisted di kolom users.failed_login_attempts +
 * locked_until. Threshold/durasi dibaca dari config('security.login_lockout'),
 * yang di-hidrasi dari tabel system_settings oleh SettingsServiceProvider —
 * editable via /platform-admin/system-settings → Security section.
 *
 * Karena state ada di DB, lockout tetap berlaku meskipun:
 *  - browser di-reload (tidak ngandelin localStorage / cache)
 *  - attacker rotate IP (kunci di akun, bukan IP)
 *  - attacker pakai client lain (curl/postman)
 *
 * Strategi tier (default): 3x → 30 detik, 5x → 5 menit, 10x → 1 jam.
 * Counter di-reset kalau:
 *   1. user login berhasil; atau
 *   2. last_failed_login_at lebih lama dari window_minutes (anti-permanent
 *      lock dari fail iseng beberapa hari lalu).
 *
 * Catatan keamanan:
 *  - Counter HANYA naik untuk user yang ada di DB. Email yang gak terdaftar
 *    tidak diapa-apain — kalau di-track juga, attacker bisa "lock" email
 *    random dengan request berulang dan membuat owner email bingung. Cegah
 *    enumeration via timing tetap mengandalkan generic error message
 *    ("Kredensial tidak cocok") dan throttle global.
 *  - Yang merespons "akun di-lock" hanya setelah password DICEK — supaya
 *    pesan locking gak jadi indikator email-mana-yang-valid (kalau di-cek
 *    sebelum password, attacker bisa bedakan akun ada/gak dari respons).
 *    Tapi kita pasang assertNotLocked() SEBELUM password check juga, untuk
 *    user yang valid memang harus dapat respons "locked" duluan. Trade-off
 *    diterima karena mitigasi enumeration sudah ada di layer lain (rate
 *    limit IP global, captcha future).
 */
class LoginAttemptService
{
    public const STATUS_LOCKED = 'locked';
    public const STATUS_FAIL = 'fail';
    public const STATUS_OK = 'ok';

    /**
     * Cek apakah user saat ini sedang ke-lock. Return null kalau bisa lanjut,
     * atau int (detik tersisa) kalau masih ke-lock.
     */
    public function lockedRetryAfter(User $user): ?int
    {
        if (! $this->isEnabled()) return null;
        if (! $user->locked_until) return null;

        $until = Carbon::parse($user->locked_until);
        // Pakai timestamp arithmetic — Carbon 3's diffInSeconds() signed by
        // default (return negatif kalau target di future), gampang salah.
        $secondsLeft = $until->getTimestamp() - now()->getTimestamp();

        return $secondsLeft > 0 ? $secondsLeft : null;
    }

    /**
     * Catat 1 failed login attempt. Inkrement counter; kalau melewati threshold
     * tier mana pun, set locked_until ke now() + tier_seconds dan tulis audit.
     *
     * Counter dianggap "fresh" kalau last_failed_login_at masih dalam window;
     * di luar window, anggap counter mulai dari 0 supaya gak ada efek
     * permanent-lock dari typo bertahun-tahun lalu.
     */
    public function recordFailure(User $user, ?string $ip = null): void
    {
        if (! $this->isEnabled()) return;

        $cfg = $this->config();
        $window = (int) ($cfg['window_minutes'] ?? 30);

        // Tentukan basis counter — reset ke 0 kalau di luar window
        $basis = 0;
        if ($user->last_failed_login_at && Carbon::parse($user->last_failed_login_at)->diffInMinutes(now()) <= $window) {
            $basis = (int) ($user->failed_login_attempts ?? 0);
        }
        $newCount = $basis + 1;

        // Tier mana yang baru saja di-trigger? Pilih tier dengan threshold
        // tertinggi yang <= newCount supaya tier tinggi mengalahkan tier rendah
        // (misal user fail ke-10 → 1 jam, bukan 30 detik).
        $tiers = $this->tiersDescending();
        $lockSeconds = null;
        $tierLabel = null;
        foreach ($tiers as $tier) {
            if ($newCount >= $tier['attempts']) {
                $lockSeconds = $tier['seconds'];
                $tierLabel = $tier['label'];
                break;
            }
        }

        $update = [
            'failed_login_attempts' => $newCount,
            'last_failed_login_at' => now(),
        ];
        if ($lockSeconds !== null) {
            $update['locked_until'] = now()->addSeconds($lockSeconds);
        }

        $user->forceFill($update)->save();

        if ($lockSeconds !== null) {
            $this->audit('login.lockout', $user, [
                'ip' => $ip,
                'attempts' => $newCount,
                'tier' => $tierLabel,
                'lock_seconds' => $lockSeconds,
                'locked_until' => $update['locked_until']->toISOString(),
            ]);
        }
    }

    /**
     * Reset counter setelah login berhasil. Tulis last_login_at + ip.
     */
    public function recordSuccess(User $user, ?string $ip = null): void
    {
        $update = [
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ];
        $user->forceFill($update)->save();
    }

    public function isEnabled(): bool
    {
        return (bool) config('security.login_lockout.enabled', true);
    }

    /**
     * Tier descending — tier terberat (paling banyak attempts) duluan, supaya
     * `recordFailure` bisa loop dan `break` di tier pertama yang match.
     *
     * @return list<array{label:string,attempts:int,seconds:int}>
     */
    private function tiersDescending(): array
    {
        $cfg = $this->config();
        $tiers = [
            ['label' => 'tier3', 'attempts' => (int) ($cfg['tier3_attempts'] ?? 10), 'seconds' => (int) ($cfg['tier3_seconds'] ?? 3600)],
            ['label' => 'tier2', 'attempts' => (int) ($cfg['tier2_attempts'] ?? 5), 'seconds' => (int) ($cfg['tier2_seconds'] ?? 300)],
            ['label' => 'tier1', 'attempts' => (int) ($cfg['tier1_attempts'] ?? 3), 'seconds' => (int) ($cfg['tier1_seconds'] ?? 30)],
        ];

        // Filter tier dengan attempts <= 0 (disabled)
        return array_values(array_filter($tiers, fn ($t) => $t['attempts'] > 0 && $t['seconds'] > 0));
    }

    private function config(): array
    {
        return (array) config('security.login_lockout', []);
    }

    private function audit(string $action, User $user, array $meta): void
    {
        try {
            AuditLog::create([
                'module' => 'auth',
                'record_id' => $user->id,
                'action' => $action,
                'user_id' => $user->id,
                'user_name' => 'system',
                'user_role' => 'system',
                'section' => 'security',
                'changes' => $meta,
                'ip_address' => $meta['ip'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LoginAttemptService audit failed', ['error' => $e->getMessage()]);
        }
    }
}
