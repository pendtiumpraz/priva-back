<?php

namespace App\Services;

use App\Jobs\FireDsrWebhookJob;
use App\Models\DsrRequest;
use App\Models\User;

/**
 * Single fan-out point for DSR lifecycle events.
 *
 * Each event triggers:
 *   1. In-app notification + email (via NotificationService) for assignee/DPO
 *   2. Webhook delivery to klien (via FireDsrWebhookJob) if app.webhook_url set
 *
 * Idempotent — safe to call multiple times for the same event.
 *
 * Events emitted:
 *   - dsr.created           (after submit)
 *   - dsr.verified          (subject completed identity check)
 *   - dsr.scope_assigned    (DPO picked information systems)
 *   - dsr.sql_pack_ready    (pack generated, ready for klien execution)
 *   - dsr.execution_progress (per-shard status change)
 *   - dsr.completed         (all executions done + cert generated)
 *   - dsr.sla_warning       (deadline approaching)
 *   - dsr.sla_breach        (deadline passed without completion)
 *   - dsr.nda_signed        (subject e-signed NDA)
 */
class DsrEventBroadcaster
{
    public const EVENT_CREATED = 'dsr.created';
    public const EVENT_VERIFIED = 'dsr.verified';
    public const EVENT_SCOPE_ASSIGNED = 'dsr.scope_assigned';
    public const EVENT_SQL_PACK_READY = 'dsr.sql_pack_ready';
    public const EVENT_EXECUTION_PROGRESS = 'dsr.execution_progress';
    public const EVENT_COMPLETED = 'dsr.completed';
    public const EVENT_SLA_WARNING = 'dsr.sla_warning';
    public const EVENT_SLA_BREACH = 'dsr.sla_breach';
    public const EVENT_NDA_SIGNED = 'dsr.nda_signed';

    /**
     * Severity per event — drives notification urgency + UI badge color.
     */
    private const SEVERITY = [
        self::EVENT_CREATED => 'medium',
        self::EVENT_VERIFIED => 'medium',
        self::EVENT_SCOPE_ASSIGNED => 'low',
        self::EVENT_SQL_PACK_READY => 'medium',
        self::EVENT_EXECUTION_PROGRESS => 'low',
        self::EVENT_COMPLETED => 'low',
        self::EVENT_SLA_WARNING => 'high',
        self::EVENT_SLA_BREACH => 'critical',
        self::EVENT_NDA_SIGNED => 'medium',
    ];

    public function emit(string $event, DsrRequest $dsr, array $extras = []): void
    {
        $dsr->loadMissing('app');
        $severity = self::SEVERITY[$event] ?? 'low';

        $title = $this->titleFor($event, $dsr);
        $body = $this->bodyFor($event, $dsr, $extras);
        $actionUrl = $this->dashboardUrl($dsr);

        // 1. In-app + email notification — recipient = assignee user
        $recipient = $dsr->assigned_to
            ? "user:{$dsr->assigned_to}"
            : "role:dpo";

        try {
            NotificationService::dispatch(
                kind: $event === self::EVENT_SLA_BREACH || $event === self::EVENT_SLA_WARNING ? 'alert' : 'info',
                severity: $severity,
                module: 'dsr',
                type: $event,
                recipient: $recipient,
                orgId: $dsr->org_id,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                metadata: array_merge([
                    'record_id' => $dsr->id,
                    'request_id' => $dsr->request_id,
                    'request_type' => $dsr->request_type,
                    'event' => $event,
                ], $extras),
            );
        } catch (\Throwable $e) {
            \Log::warning("DsrEventBroadcaster notification failed for {$event}/{$dsr->id}: " . $e->getMessage());
        }

        // 2. Webhook to klien (if configured on app)
        if ($dsr->app && !empty($dsr->app->webhook_url)) {
            try {
                FireDsrWebhookJob::dispatch(
                    webhookUrl: $dsr->app->webhook_url,
                    signingSecret: $dsr->app->embed_token, // use embed_token as HMAC key
                    event: $event,
                    payload: $this->webhookPayload($event, $dsr, $extras),
                );
            } catch (\Throwable $e) {
                \Log::warning("DsrEventBroadcaster webhook dispatch failed for {$event}/{$dsr->id}: " . $e->getMessage());
            }
        }
    }

    private function titleFor(string $event, DsrRequest $dsr): string
    {
        return match ($event) {
            self::EVENT_CREATED => "DSR baru diterima: {$dsr->request_id}",
            self::EVENT_VERIFIED => "DSR {$dsr->request_id} terverifikasi",
            self::EVENT_SCOPE_ASSIGNED => "DSR {$dsr->request_id}: scope diatur",
            self::EVENT_SQL_PACK_READY => "DSR {$dsr->request_id}: SQL pack siap",
            self::EVENT_EXECUTION_PROGRESS => "DSR {$dsr->request_id}: update eksekusi",
            self::EVENT_COMPLETED => "DSR {$dsr->request_id} selesai ✓",
            self::EVENT_SLA_WARNING => "⚠ DSR {$dsr->request_id}: deadline {$this->humanDeadline($dsr)}",
            self::EVENT_SLA_BREACH => "🚨 DSR {$dsr->request_id}: SLA terlewat",
            self::EVENT_NDA_SIGNED => "DSR {$dsr->request_id}: NDA tertanda tangani",
            default => "DSR {$dsr->request_id}",
        };
    }

    private function bodyFor(string $event, DsrRequest $dsr, array $extras): string
    {
        $type = $dsr->request_type;
        return match ($event) {
            self::EVENT_CREATED => "Permintaan {$type} baru menunggu verifikasi identitas pemohon.",
            self::EVENT_VERIFIED => "Pemohon sudah verifikasi email. Anda dapat memulai scope picker.",
            self::EVENT_SCOPE_ASSIGNED => "Scope ke " . count($dsr->scopes()->pluck('id')) . " sistem telah ditetapkan.",
            self::EVENT_SQL_PACK_READY => "SQL pack sudah di-generate. Download dan eksekusi di sistem klien.",
            self::EVENT_EXECUTION_PROGRESS => $extras['notes'] ?? "Status eksekusi diperbarui.",
            self::EVENT_COMPLETED => "Semua eksekusi selesai. Sertifikat subject + internal sudah di-generate.",
            self::EVENT_SLA_WARNING => "Deadline SLA tinggal " . ($extras['hours_left'] ?? '?') . " jam. Segera selesaikan!",
            self::EVENT_SLA_BREACH => "Deadline UU PDP (72 jam) terlewat. Segera proses dan dokumentasikan justifikasi.",
            self::EVENT_NDA_SIGNED => "Pemohon sudah menandatangani NDA. Permintaan akses bisa diproses.",
            default => '',
        };
    }

    private function dashboardUrl(DsrRequest $dsr): string
    {
        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        return $base . '/dsr/' . $dsr->id;
    }

    private function humanDeadline(DsrRequest $dsr): string
    {
        if (!$dsr->deadline_at) return '?';
        $diff = now()->diffInHours($dsr->deadline_at, false);
        if ($diff < 0) return 'sudah lewat';
        if ($diff < 1) return '< 1 jam lagi';
        return "{$diff} jam lagi";
    }

    /**
     * Build webhook payload — minimal but enough for klien to act.
     * Email + name NOT included by default (PII minimization). Klien call
     * Privasimu Partner API with embed_token to fetch full record if needed.
     */
    private function webhookPayload(string $event, DsrRequest $dsr, array $extras): array
    {
        return array_merge([
            'request_id' => $dsr->request_id,
            'dsr_uuid' => $dsr->id,
            'request_type' => $dsr->request_type,
            'status' => $dsr->status,
            'verification_status' => $dsr->verification_status,
            'created_at' => optional($dsr->created_at)->toIso8601String(),
            'verified_at' => optional($dsr->verified_at)->toIso8601String(),
            'deadline_at' => optional($dsr->deadline_at)->toIso8601String(),
            'closed_at' => optional($dsr->closed_at)->toIso8601String(),
            'app_code' => $dsr->app?->app_code,
        ], $extras);
    }
}
