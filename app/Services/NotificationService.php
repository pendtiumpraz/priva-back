<?php

namespace App\Services;

use App\Models\SecurityAlert;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Single entry point for all notification dispatches.
 *
 * Wraps SecurityAlert::create() with:
 * - recipient fan-out (single user | role | entire org | array)
 * - per-user preference gate (in-app always on by default; WA/email opt-in)
 * - WA deep-link builder (https://wa.me/{phone}?text={msg})
 * - severity → priority auto-mapping for sort order
 *
 * Usage:
 *   NotificationService::dispatch(
 *       kind: 'alert',
 *       severity: 'high',
 *       module: 'ropa',
 *       type: 'ropa.assigned',
 *       recipient: 'user:' . $user->id,           // or 'role:dpo', 'org:' . $orgId
 *       orgId: $record->org_id,
 *       title: 'ROPA HR-001 di-assign ke Anda',
 *       body: 'Review aktivitas Rekrutmen Kandidat',
 *       actionUrl: '/ropa/' . $record->id,
 *       metadata: ['record_id' => $record->id]
 *   );
 */
class NotificationService
{
    public const KINDS = ['alert', 'warning', 'info'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * @param string $kind alert|warning|info
     * @param string $severity critical|high|medium|low
     * @param string $module ropa|dpia|dsr|breach|license|system|...
     * @param string $type granular event code (e.g. 'ropa.assigned')
     * @param string $recipient "user:<id>" | "role:<name>" | "org:<id>"
     * @param string|null $orgId tenant scope (null for platform-wide root notifs)
     * @param string $title short subject line
     * @param string $body longer description
     * @param string|null $actionUrl deep-link or wa.me URL
     * @param array $metadata additional payload
     * @return array the created alert rows (one per recipient)
     */
    public static function dispatch(
        string $kind,
        string $severity,
        string $module,
        string $type,
        string $recipient,
        ?string $orgId = null,
        string $title = '',
        string $body = '',
        ?string $actionUrl = null,
        array $metadata = []
    ): array {
        // Two-level kill switch:
        // - Root (AppSetting global) → off kills everything.
        // - Superadmin (Organization.settings.notifications_enabled) → off
        //   silences notifications scoped to that tenant.
        // Platform-level notifications (org_id=null) only pass the root check.
        if (!self::isEnabled($orgId)) {
            return [];
        }

        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'info';
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'low';
        }

        $recipients = self::resolveRecipients($recipient, $orgId);
        $priority = self::severityToPriority($severity);

        $created = [];
        foreach ($recipients as $user) {
            // Gate: if user has opted out of this kind/module for in-app channel, skip.
            if ($user && !NotificationPreference::isEnabled($user->id, $kind, $module, 'in_app')) {
                continue;
            }

            $row = SecurityAlert::create([
                'org_id' => $orgId ?? ($user?->org_id),
                'rule_code' => $type,
                'type' => $type,
                'kind' => $kind,
                'severity' => $severity,
                'module' => $module,
                'record_id' => $metadata['record_id'] ?? null,
                'recipient_id' => $user?->id,
                'recipient_role' => $user ? null : self::extractRole($recipient),
                'priority' => $priority,
                'action_url' => $actionUrl,
                'title' => $title,
                'description' => $body,
                'status' => 'open',
                'metadata' => $metadata,
            ]);
            $created[] = $row;

            // Fire email side-channel if user has opted in AND digest=instant.
            // For `daily` / `hourly` digest, scheduler batches them.
            if ($user && $user->email && NotificationPreference::isEnabled($user->id, $kind, $module, 'email')) {
                $pref = NotificationPreference::where('user_id', $user->id)
                    ->where('kind', $kind)
                    ->whereIn('module', [$module, '*'])
                    ->where('channel', 'email')
                    ->first();
                $digest = $pref?->digest ?? 'instant';
                if ($digest === 'instant' || $digest === null) {
                    try {
                        \Mail::to($user->email)->queue(
                            new \App\Mail\NotificationMail($row, $user->name ?? 'there')
                        );
                    } catch (\Throwable $e) {
                        \Log::warning('NotificationMail queue failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // Telegram fan-out: fire once per dispatch to the org's shared
        // Telegram channel (not per-user). Only if org has integration
        // configured. Org-level preference gate via integration_config.
        $telegramOrgId = $orgId ?? (count($recipients) > 0 ? $recipients[0]?->org_id : null);
        if ($telegramOrgId) {
            try {
                $emoji = match ($kind) { 'alert' => '🚨', 'warning' => '⚠️', 'info' => 'ℹ️', default => '🔔' };
                $tgText = "{$emoji} *" . strtoupper($kind) . " · " . strtoupper($severity) . "*\n";
                $tgText .= "*" . ($title ?: 'Notifikasi') . "*\n\n";
                if ($body) $tgText .= $body . "\n\n";
                if ($actionUrl && !str_starts_with($actionUrl, 'https://wa.me/')) {
                    $tgText .= "_Detail:_ {$actionUrl}";
                }
                self::sendTelegram($telegramOrgId, $tgText);
            } catch (\Throwable $e) {
                \Log::warning('Telegram dispatch failed: ' . $e->getMessage());
            }
        }

        return $created;
    }

    /**
     * Two-tier kill switch:
     *  1. Global (root): AppSetting `features.notifications_enabled`.
     *  2. Per-tenant (superadmin): `organizations.settings.notifications_enabled`.
     *
     * Global off → returns false regardless of tenant. Global on + tenant
     * off → returns false only for notifications scoped to that tenant;
     * platform-level ($orgId=null) still pass through.
     * Default when missing = enabled.
     */
    public static function isEnabled(?string $orgId = null): bool
    {
        try {
            $global = \App\Models\AppSetting::get('features.notifications_enabled');
            $globalOn = $global === null || (string) $global === '1' || (string) $global === 'true';
            if (!$globalOn) return false;

            if ($orgId) {
                $org = \App\Models\Organization::find($orgId);
                if ($org) {
                    $settings = $org->settings ?? [];
                    if (is_array($settings) && array_key_exists('notifications_enabled', $settings)) {
                        return (bool) $settings['notifications_enabled'];
                    }
                }
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public static function isSchedulerEnabled(): bool
    {
        try {
            $val = \App\Models\AppSetting::get('features.notifications_scheduler_enabled');
            return $val === null || (string) $val === '1' || (string) $val === 'true';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Builds a Telegram-formatted Markdown message for a breach row.
     * Pulls via Eloquent so encryption casts are applied — never receives
     * ciphertext. Returns an empty string if the breach object is missing.
     */
    public static function buildBreachTelegramMessage(\App\Models\BreachIncident $breach): string
    {
        $sev = strtoupper($breach->severity ?? '—');
        $status = strtoupper($breach->status ?? '—');
        $msg  = "🚨 *INCIDENT ALERT: {$sev}* 🚨\n\n";
        $msg .= "*Incident Code:* " . ($breach->incident_code ?? '—') . "\n";
        $msg .= "*Title:* " . ($breach->title ?? '—') . "\n";
        $msg .= "*Status:* {$status}\n";
        $msg .= "*Detected At:* " . ($breach->detected_at?->toDateTimeString() ?? '—') . "\n\n";
        if (!empty($breach->description)) {
            $msg .= "*Description:*\n" . $breach->description . "\n\n";
        }
        if ($breach->affected_subjects_count) {
            $msg .= "*Affected Subjects:* " . (int) $breach->affected_subjects_count . "\n";
        }
        $msg .= "\n🔒 _Check Privasimu Dashboard for full detail._";
        return $msg;
    }

    /**
     * Send a notification to the tenant's configured Telegram bot/chat.
     * Silently no-op if Telegram is not configured for this org. Used by
     * dispatch() as a side channel when user preferences have
     * channel=telegram enabled.
     */
    public static function sendTelegram(string $orgId, string $text): bool
    {
        try {
            $org = \App\Models\Organization::find($orgId);
            if (!$org) return false;
            $cfg = $org->integration_config['telegram'] ?? [];
            if (empty($cfg['bot_token']) || empty($cfg['chat_id'])) return false;

            $resp = \Illuminate\Support\Facades\Http::post(
                "https://api.telegram.org/bot{$cfg['bot_token']}/sendMessage",
                ['chat_id' => $cfg['chat_id'], 'text' => $text, 'parse_mode' => 'Markdown']
            );
            return $resp->successful();
        } catch (\Throwable $e) {
            \Log::warning('Telegram send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a WhatsApp deep-link: https://wa.me/{digits}?text={urlencoded}
     * Returns null if phone is empty.
     */
    public static function buildWaUrl(?string $phone, string $message = ''): ?string
    {
        if (!$phone) return null;
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) return null;
        // Indonesian numbers often start with 0 — strip and prepend 62
        if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
        $url = 'https://wa.me/' . $digits;
        if ($message !== '') $url .= '?text=' . rawurlencode($message);
        return $url;
    }

    /**
     * Expand a recipient spec into a list of User models (or [null] for
     * role broadcasts when we don't want per-user fan-out yet).
     */
    private static function resolveRecipients(string $spec, ?string $orgId): array
    {
        if (str_starts_with($spec, 'user:')) {
            $id = substr($spec, 5);
            $user = User::find($id);
            return $user ? [$user] : [];
        }
        if (str_starts_with($spec, 'role:')) {
            $role = substr($spec, 5);
            $q = User::where('role', $role);
            if ($orgId) $q->where('org_id', $orgId);
            return $q->get()->all();
        }
        if (str_starts_with($spec, 'org:')) {
            $id = substr($spec, 4);
            return User::where('org_id', $id)->get()->all();
        }
        // Unknown spec — return [null] so a broadcast row is still created
        // (recipient_id null = org-wide notification, visible to all in org).
        return [null];
    }

    private static function extractRole(string $spec): ?string
    {
        return str_starts_with($spec, 'role:') ? substr($spec, 5) : null;
    }

    private static function severityToPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 100,
            'high' => 75,
            'medium' => 50,
            'low' => 25,
            default => 50,
        };
    }
}
