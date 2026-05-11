<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Kumpulkan snapshot status semua security knob yang sudah di-implement
 * di Privasimu. Output dipakai untuk:
 *   - Display di tab "Pentest" → section "Security Posture Summary"
 *   - Export PDF (annex untuk pentest report / compliance audit)
 *
 * Grouping mengikuti tab di SecuritySection UI. Tiap item include:
 *   - key (DB key di system_settings)
 *   - label (human-readable)
 *   - value (nilai aktual dari config / DB)
 *   - default (rekomendasi)
 *   - status (enabled / disabled / configured / default)
 *   - risk_if_misconfigured (info, low/medium/high)
 */
class SecurityPostureService
{
    public function snapshot(): array
    {
        $now = Carbon::now();

        $groups = [
            $this->groupLockout(),
            $this->groupPassword(),
            $this->groupTwoFactor(),
            $this->groupEmailVerify(),
            $this->groupWebhookHmac(),
            $this->groupHeaders(),
            $this->groupCors(),
            $this->groupTokenExpiry(),
            $this->groupAiLimits(),
            $this->groupPlatform(),
            $this->groupSessions(),
        ];

        $total = 0; $enabled = 0; $disabled = 0;
        foreach ($groups as $g) {
            foreach ($g['items'] as $item) {
                $total++;
                if (($item['status'] ?? 'default') === 'enabled' || ($item['status'] ?? '') === 'configured') $enabled++;
                if (($item['status'] ?? '') === 'disabled') $disabled++;
            }
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'generated_at_human' => $now->locale('id')->isoFormat('D MMMM YYYY, HH:mm') . ' WIB',
            'platform' => config('app.name', 'Privasimu'),
            'platform_url' => config('app.url', '-'),
            'deployment_mode' => config('ai.deployment_mode', 'saas'),
            'groups' => $groups,
            'summary' => [
                'total_settings' => $total,
                'enabled' => $enabled,
                'disabled' => $disabled,
            ],
            'implementation_notes' => $this->implementationNotes(),
        ];
    }

    private function groupLockout(): array
    {
        $enabled = (bool) config('security.login_lockout.enabled', true);
        return [
            'name' => 'Login Lockout',
            'description' => 'Penguncian akun otomatis setelah N percobaan login gagal. Mencegah brute-force password.',
            'master_status' => $enabled ? 'enabled' : 'disabled',
            'items' => [
                $this->item('login_lockout.enabled', 'Lockout enforcement', $enabled, true, $enabled ? 'enabled' : 'disabled'),
                $this->item('login_lockout.tier1', 'Tier 1 — N fail → lock detik',
                    config('security.login_lockout.tier1_attempts').'x → '.config('security.login_lockout.tier1_seconds').'s',
                    '3x → 30s', 'configured'),
                $this->item('login_lockout.tier2', 'Tier 2',
                    config('security.login_lockout.tier2_attempts').'x → '.config('security.login_lockout.tier2_seconds').'s',
                    '5x → 300s', 'configured'),
                $this->item('login_lockout.tier3', 'Tier 3',
                    config('security.login_lockout.tier3_attempts').'x → '.config('security.login_lockout.tier3_seconds').'s',
                    '10x → 3600s', 'configured'),
                $this->item('login_lockout.window', 'Sliding window (menit)',
                    config('security.login_lockout.window_minutes'), 30, 'configured'),
            ],
        ];
    }

    private function groupPassword(): array
    {
        return [
            'name' => 'Password Policy',
            'description' => 'Aturan password baru saat register / ganti password.',
            'master_status' => 'enabled',
            'items' => [
                $this->item('password.min_length', 'Panjang minimal', config('security.password.min_length'), 12, 'configured'),
                $this->boolItem('password.require_uppercase', 'Wajib huruf besar', config('security.password.require_uppercase')),
                $this->boolItem('password.require_lowercase', 'Wajib huruf kecil', config('security.password.require_lowercase')),
                $this->boolItem('password.require_digit', 'Wajib angka', config('security.password.require_digit')),
                $this->boolItem('password.require_symbol', 'Wajib simbol', config('security.password.require_symbol')),
                $this->boolItem('password.block_common', 'Block top-100 common passwords', config('security.password.block_common')),
                $this->boolItem('password.block_email_match', 'Block password = email/local-part', config('security.password.block_email_match')),
                $this->boolItem('password.check_hibp', 'HaveIBeenPwned API check (opt-in)', config('security.password.check_hibp'), false),
                $this->item('password.rotation_days', 'Force rotation (hari, 0=off)', config('security.password.rotation_days'), 0,
                    (int) config('security.password.rotation_days', 0) > 0 ? 'enabled' : 'disabled'),
            ],
        ];
    }

    private function groupTwoFactor(): array
    {
        $masterOn = (bool) config('security.2fa_enabled', true);
        return [
            'name' => '2FA TOTP',
            'description' => 'Two-Factor Authentication via authenticator app (Google Authenticator, Authy, dll).',
            'master_status' => $masterOn ? 'enabled' : 'disabled',
            'items' => [
                $this->boolItem('2fa.enabled', 'Fitur 2FA tersedia (opt-in user)', $masterOn),
                $this->boolItem('2fa.required_for_root', 'Force untuk role Root', config('security.2fa_required_for_root'), false),
                $this->boolItem('2fa.required_for_superadmin', 'Force untuk role Superadmin', config('security.2fa_required_for_superadmin'), false),
                $this->boolItem('2fa.required_for_admin', 'Force untuk role Admin', config('security.2fa_required_for_admin'), false),
                $this->boolItem('2fa.required_for_dpo', 'Force untuk role DPO', config('security.2fa_required_for_dpo'), false),
                $this->item('2fa.max_verify_attempts', 'Max wrong code per challenge', config('security.2fa_max_verify_attempts'), 5, 'configured'),
            ],
        ];
    }

    private function groupEmailVerify(): array
    {
        $on = (bool) config('security.email_verification_required', false);
        return [
            'name' => 'Email Verification',
            'description' => 'Verifikasi email saat register lewat magic link.',
            'master_status' => $on ? 'enabled' : 'disabled',
            'items' => [
                $this->boolItem('email_verification.required', 'Email verification wajib saat register', $on, false),
                $this->item('email_verification.grace_minutes', 'Grace period (menit)',
                    config('security.email_verification_grace_minutes'), 60, 'configured'),
            ],
        ];
    }

    private function groupWebhookHmac(): array
    {
        $on = (bool) config('security.webhook_hmac_required', false);
        return [
            'name' => 'Webhook HMAC Signature',
            'description' => 'Verifikasi HMAC-SHA256 + anti-replay timestamp untuk incoming webhook (mis. SOCRadar).',
            'master_status' => $on ? 'enabled' : 'disabled',
            'items' => [
                $this->boolItem('webhook.hmac_required', 'HMAC signature wajib di semua webhook', $on, false),
                $this->item('webhook.timestamp_tolerance', 'Timestamp tolerance (detik)',
                    config('security.webhook_timestamp_tolerance_seconds'), 300, 'configured'),
            ],
        ];
    }

    private function groupHeaders(): array
    {
        $on = (bool) config('security.headers.enabled', true);
        return [
            'name' => 'Response Headers',
            'description' => 'HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy di-stamp ke setiap response.',
            'master_status' => $on ? 'enabled' : 'disabled',
            'items' => [
                $this->boolItem('headers.enabled', 'Stamping security headers', $on),
                $this->boolItem('headers.hsts_enabled', 'HSTS (HTTPS enforcement)', config('security.headers.hsts_enabled')),
                $this->item('headers.hsts_max_age', 'HSTS max-age (detik)', config('security.headers.hsts_max_age'), 31536000, 'configured'),
                $this->item('headers.frame_options', 'X-Frame-Options', config('security.headers.frame_options'), 'SAMEORIGIN', 'configured'),
                $this->item('headers.referrer_policy', 'Referrer-Policy', config('security.headers.referrer_policy'), 'strict-origin-when-cross-origin', 'configured'),
            ],
        ];
    }

    private function groupCors(): array
    {
        $origins = config('cors.allowed_origins', []);
        $count = is_array($origins) ? count($origins) : 0;
        return [
            'name' => 'CORS Allowlist',
            'description' => 'Allowlist origin frontend (replace Laravel default `*`).',
            'master_status' => $count > 0 ? 'configured' : 'disabled',
            'items' => [
                $this->item('cors.allowed_origins_count', 'Jumlah origin di allowlist', $count, '> 0', $count > 0 ? 'configured' : 'disabled'),
                $this->boolItem('cors.allow_credentials', 'Support credentials', config('cors.supports_credentials'), false),
                $this->item('cors.max_age', 'Preflight cache (detik)', config('cors.max_age'), 3600, 'configured'),
            ],
        ];
    }

    private function groupTokenExpiry(): array
    {
        $lifetime = (int) config('sanctum.expiration', 0);
        return [
            'name' => 'Sanctum Token Expiry',
            'description' => 'Hard expiry + sliding refresh untuk Sanctum personal access token.',
            'master_status' => $lifetime > 0 ? 'enabled' : 'disabled',
            'items' => [
                $this->item('token.lifetime_minutes', 'Token lifetime (menit)', $lifetime, 10080,
                    $lifetime > 0 ? 'enabled' : 'disabled'),
                $this->item('token.refresh_threshold_pct', 'Auto-refresh threshold (%)',
                    config('security.token.refresh_threshold_pct'), 50, 'configured'),
            ],
        ];
    }

    private function groupAiLimits(): array
    {
        return [
            'name' => 'AI Prompt Limits',
            'description' => 'Cegah biaya AI tak terduga + abuse credit tenant.',
            'master_status' => 'configured',
            'items' => [
                $this->item('ai.max_prompt_chars', 'Max prompt total (chars)',
                    config('security.ai.max_prompt_chars'), 24000, 'configured'),
                $this->item('ai.max_message_chars', 'Max user message (chars)',
                    config('security.ai.max_message_chars'), 4000, 'configured'),
                $this->item('ai.max_attachment_chars', 'Max attachment text (chars)',
                    config('security.ai.max_attachment_chars'), 12000, 'configured'),
            ],
        ];
    }

    private function groupPlatform(): array
    {
        $rootOn = (bool) config('security.ip_allowlist_enabled_for_root', false);
        $saOn = (bool) config('security.ip_allowlist_enabled_for_superadmin', false);
        $retention = (int) config('security.audit_log_retention_days', 0);
        return [
            'name' => 'Platform Hardening',
            'description' => 'IP allowlist untuk role platform-level + audit log retention.',
            'master_status' => ($rootOn || $saOn || $retention > 0) ? 'configured' : 'disabled',
            'items' => [
                $this->boolItem('ip_allowlist.enforce_root', 'IP allowlist enforcement untuk Root', $rootOn, false),
                $this->boolItem('ip_allowlist.enforce_superadmin', 'IP allowlist enforcement untuk Superadmin', $saOn, false),
                $this->item('ip_allowlist.root_count', 'Jumlah IP di Root allowlist',
                    count((array) config('security.ip_allowlist_root', [])), 0, 'info'),
                $this->item('ip_allowlist.superadmin_count', 'Jumlah IP di Superadmin allowlist',
                    count((array) config('security.ip_allowlist_superadmin', [])), 0, 'info'),
                $this->item('audit_log.retention_days', 'Audit log retention (hari, 0=keep forever)',
                    $retention, 0, $retention > 0 ? 'enabled' : 'disabled'),
            ],
        ];
    }

    private function groupSessions(): array
    {
        $max = (int) config('security.max_sessions_per_user', 0);
        $tenantRate = (bool) config('security.tenant_rate_limit_enabled', true);
        return [
            'name' => 'Session & Rate Limit',
            'description' => 'Concurrent session limit + per-tenant rate limit.',
            'master_status' => 'configured',
            'items' => [
                $this->item('sessions.max_per_user', 'Max concurrent sessions per user (0=unlimited)',
                    $max, 0, $max > 0 ? 'enabled' : 'disabled'),
                $this->boolItem('tenant_rate_limit.enabled', 'Per-tenant rate limit enforcement', $tenantRate),
                $this->item('tenant_rate_limit.per_minute', 'Request per menit per tenant',
                    config('security.tenant_rate_limit_per_minute'), 300, 'configured'),
            ],
        ];
    }

    private function item(string $key, string $label, mixed $value, mixed $default, string $status): array
    {
        return compact('key', 'label', 'value', 'default', 'status');
    }

    private function boolItem(string $key, string $label, mixed $value, bool $default = true): array
    {
        $bool = (bool) $value;
        return [
            'key' => $key,
            'label' => $label,
            'value' => $bool ? 'AKTIF' : 'NONAKTIF',
            'default' => $default ? 'AKTIF' : 'NONAKTIF',
            'status' => $bool ? 'enabled' : 'disabled',
        ];
    }

    private function implementationNotes(): array
    {
        return [
            'Semua security knob disimpan di tabel `system_settings` dan dikelola via '
                . 'UI `/platform-admin/system-settings → Security` (root/superadmin only).',
            'Default values dipilih konservatif: fitur yang mempengaruhi user / integrasi '
                . 'existing default OFF supaya tidak ada disruption saat deploy.',
            'Pattern config: setting di DB → di-hidrasi ke Laravel config saat boot oleh '
                . 'SettingsServiceProvider → service code baca via `config()` helper.',
            'CI SAST: Dependabot, composer audit, npm audit, Larastan level 5, ESLint, '
                . 'build verification — jalan di setiap push/PR + weekly schedule.',
            'Audit log mencatat semua perubahan setting + semua action security-relevant '
                . '(login, logout, 2FA setup/verify, password change, dst).',
        ];
    }
}
