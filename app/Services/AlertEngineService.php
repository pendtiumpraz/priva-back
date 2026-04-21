<?php

namespace App\Services;

use App\Models\SecurityAlert;
use App\Models\DsrRequest;
use App\Models\BreachIncident;
use App\Models\Vendor;
use App\Models\Ropa;
use App\Models\Dpia;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AlertEngineService
{
    /**
     * Run all anomaly detection rules for an organization.
     * Called manually or via scheduler.
     */
    public function runAllRules(string $orgId): array
    {
        $generated = [];

        $generated = array_merge($generated, $this->checkDsrDeadlineWarning($orgId));
        $generated = array_merge($generated, $this->checkDsrOverdue($orgId));
        $generated = array_merge($generated, $this->checkBreachOpen($orgId));
        $generated = array_merge($generated, $this->checkBreachDeadline72h($orgId));
        $generated = array_merge($generated, $this->checkDpaExpiring($orgId));
        $generated = array_merge($generated, $this->checkRopaHighRiskNoDpia($orgId));
        $generated = array_merge($generated, $this->checkRopaReview90d($orgId));
        $generated = array_merge($generated, $this->checkDpiaReviewDue($orgId));
        $generated = array_merge($generated, $this->checkStaleGapAssessment($orgId));

        return $generated;
    }

    /**
     * Breach 72h notification deadline approaching (H-24).
     */
    protected function checkBreachDeadline72h(string $orgId): array
    {
        $alerts = [];
        $near = BreachIncident::where('org_id', $orgId)
            ->whereNotNull('notification_deadline')
            ->whereBetween('notification_deadline', [Carbon::now()->addHours(20), Carbon::now()->addHours(28)])
            ->get();

        foreach ($near as $b) {
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('type', 'breach.deadline.h24')
                ->where('record_id', $b->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();
            if ($exists) continue;

            $alerts = array_merge($alerts, \App\Services\NotificationService::dispatch(
                kind: 'alert',
                severity: 'critical',
                module: 'breach',
                type: 'breach.deadline.h24',
                recipient: 'role:dpo',
                orgId: $orgId,
                title: "🚨 Breach {$b->incident_code} — 24 jam ke deadline notifikasi",
                body: 'Batas 72 jam notifikasi regulator tinggal 24 jam. Segera finalisasi.',
                actionUrl: "/breach/{$b->id}",
                metadata: ['record_id' => $b->id]
            ));
        }
        return $alerts;
    }

    /**
     * ROPA that was approved >90 days ago and hasn't been reviewed since.
     * Fires once per record via type dedup. Reminds assignees to re-review.
     */
    protected function checkRopaReview90d(string $orgId): array
    {
        $alerts = [];
        $stale = Ropa::where('org_id', $orgId)
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->where('approved_at', '<', Carbon::now()->subDays(90))
            ->where('updated_at', '<', Carbon::now()->subDays(90))
            ->get();

        foreach ($stale as $r) {
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('type', 'ropa.review.90d')
                ->where('record_id', $r->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();
            if ($exists) continue;

            $assignees = is_array($r->assignees) ? $r->assignees : [];
            $recipient = count($assignees) > 0 ? 'user:' . $assignees[0] : 'role:dpo';

            $alerts = array_merge($alerts, \App\Services\NotificationService::dispatch(
                kind: 'warning',
                severity: 'medium',
                module: 'ropa',
                type: 'ropa.review.90d',
                recipient: $recipient,
                orgId: $orgId,
                title: "📋 Review ROPA {$r->registration_number}",
                body: 'ROPA sudah disetujui >90 hari — sudah waktunya review ulang akurasi data.',
                actionUrl: "/ropa/{$r->id}",
                metadata: ['record_id' => $r->id]
            ));
        }
        return $alerts;
    }

    /**
     * DPIA review reminder: 30d for high-risk, 180d for others.
     */
    protected function checkDpiaReviewDue(string $orgId): array
    {
        $alerts = [];
        $dpias = Dpia::where('org_id', $orgId)
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->get();

        foreach ($dpias as $d) {
            $threshold = $d->risk_level === 'high' ? 30 : 180;
            $dueDate = Carbon::parse($d->approved_at)->addDays($threshold);
            if ($dueDate->isFuture()) continue;

            $type = 'dpia.review.' . ($d->risk_level === 'high' ? '30d' : '180d');
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('type', $type)
                ->where('record_id', $d->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();
            if ($exists) continue;

            $alerts = array_merge($alerts, \App\Services\NotificationService::dispatch(
                kind: 'warning',
                severity: $d->risk_level === 'high' ? 'high' : 'medium',
                module: 'dpia',
                type: $type,
                recipient: 'role:dpo',
                orgId: $orgId,
                title: "🔄 DPIA {$d->registration_number} perlu review",
                body: "DPIA {$d->risk_level}-risk sudah lewat siklus review {$threshold} hari.",
                actionUrl: "/dpia/{$d->id}",
                metadata: ['record_id' => $d->id]
            ));
        }
        return $alerts;
    }

    /**
     * DSR deadline warning: ~24h before the 72h regulatory deadline.
     * Fires once per record via rule_code dedup.
     */
    protected function checkDsrDeadlineWarning(string $orgId): array
    {
        $alerts = [];
        $soon = DsrRequest::where('org_id', $orgId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [Carbon::now()->addHours(20), Carbon::now()->addHours(28)])
            ->get();

        foreach ($soon as $dsr) {
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('rule_code', 'dsr.deadline.h24')
                ->where('record_id', $dsr->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();
            if ($exists) continue;

            $alert = \App\Services\NotificationService::dispatch(
                kind: 'warning',
                severity: 'high',
                module: 'dsr',
                type: 'dsr.deadline.h24',
                recipient: 'role:dpo',
                orgId: $orgId,
                title: "⏰ DSR #{$dsr->reference_number} — 24 jam tersisa",
                body: "Batas waktu respon DSR tinggal 24 jam. Segera tangani permintaan subjek data.",
                actionUrl: "/dsr/{$dsr->id}",
                metadata: [
                    'record_id' => $dsr->id,
                    'deadline_at' => $dsr->deadline_at,
                    'reference_number' => $dsr->reference_number,
                ]
            );
            $alerts = array_merge($alerts, $alert);
        }
        return $alerts;
    }

    /**
     * Rule 1: DSR requests pending > 3 days without response.
     */
    protected function checkDsrOverdue(string $orgId): array
    {
        $alerts = [];
        $overdueDsrs = DsrRequest::where('org_id', $orgId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->where('created_at', '<', Carbon::now()->subDays(3))
            ->get();

        foreach ($overdueDsrs as $dsr) {
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('rule_code', 'dsr_overdue')
                ->where('record_id', $dsr->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();

            if (!$exists) {
                $daysSince = (int) Carbon::parse($dsr->created_at)->diffInDays(now());
                $alerts = array_merge($alerts, \App\Services\NotificationService::dispatch(
                    kind: 'alert',
                    severity: $daysSince > 7 ? 'critical' : 'high',
                    module: 'dsr',
                    type: 'dsr_overdue',
                    recipient: 'role:dpo',
                    orgId: $orgId,
                    title: "DSR #{$dsr->reference_number} melebihi batas waktu",
                    body: "Permintaan DSR belum ditangani {$daysSince} hari. Batas maksimal 3 hari.",
                    actionUrl: "/dsr/{$dsr->id}",
                    metadata: [
                        'record_id' => $dsr->id,
                        'registration_number' => $dsr->reference_number ?? null,
                        'days_overdue' => $daysSince,
                    ]
                ));
            }
        }
        return $alerts;
    }

    /**
     * Rule 2: Breach incidents open for > 24 hours.
     */
    protected function checkBreachOpen(string $orgId): array
    {
        $alerts = [];
        $openBreaches = BreachIncident::where('org_id', $orgId)
            ->whereIn('status', ['new', 'investigating']) // Actual status for breaches
            ->where('created_at', '<', Carbon::now()->subHours(24))
            ->get();

        foreach ($openBreaches as $breach) {
            $exists = SecurityAlert::where('org_id', $orgId)
                ->where('rule_code', 'breach_unresolved')
                ->where('record_id', $breach->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();

            if (!$exists) {
                $hoursSince = Carbon::parse($breach->created_at)->diffInHours(now());
                $alert = SecurityAlert::create([
                    'org_id' => $orgId,
                    'rule_code' => 'breach_unresolved',
                    'severity' => 'critical',
                    'title' => "Insiden data breach belum ditangani ({$hoursSince} jam)",
                    'description' => "Insiden breach telah terbuka selama lebih dari 24 jam. Segera lakukan eskalasi dan notifikasi sesuai prosedur.",
                    'module' => 'breach',
                    'record_id' => $breach->id,
                    'metadata' => [
                        'hours_open' => $hoursSince,
                    ],
                ]);
                $alerts[] = $alert;
            }
        }
        return $alerts;
    }

    /**
     * Rule 3: Vendor DPA expired or expiring within 30 days.
     */
    protected function checkDpaExpiring(string $orgId): array
    {
        $alerts = [];
        $vendors = Vendor::where('org_id', $orgId)->get();

        foreach ($vendors as $vendor) {
            $dpaExpiry = $vendor->dpa_expiry;
            if (!$dpaExpiry) {
                // No DPA at all
                $exists = SecurityAlert::where('org_id', $orgId)
                    ->where('rule_code', 'dpa_missing')
                    ->where('record_id', $vendor->id)
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->exists();

                if (!$exists) {
                    $alert = SecurityAlert::create([
                        'org_id' => $orgId,
                        'rule_code' => 'dpa_missing',
                        'severity' => 'high',
                        'title' => "Vendor '{$vendor->name}' tidak memiliki DPA",
                        'description' => "Vendor belum memiliki Data Processing Agreement (DPA). Ini merupakan pelanggaran kepatuhan regulasi.",
                        'module' => 'vendor-risk',
                        'record_id' => $vendor->id,
                        'metadata' => ['vendor_name' => $vendor->name],
                    ]);
                    $alerts[] = $alert;
                }
                continue;
            }

            $expiryDate = Carbon::parse($dpaExpiry);
            $daysUntilExpiry = now()->diffInDays($expiryDate, false);

            if ($daysUntilExpiry <= 30) {
                $ruleCode = $daysUntilExpiry < 0 ? 'dpa_expired' : 'dpa_expiring';
                $exists = SecurityAlert::where('org_id', $orgId)
                    ->where('rule_code', $ruleCode)
                    ->where('record_id', $vendor->id)
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->exists();

                if (!$exists) {
                    $severity = $daysUntilExpiry < 0 ? 'critical' : ($daysUntilExpiry <= 7 ? 'high' : 'medium');
                    $titleText = $daysUntilExpiry < 0
                        ? "DPA vendor '{$vendor->name}' sudah kedaluwarsa"
                        : "DPA vendor '{$vendor->name}' akan kedaluwarsa dalam {$daysUntilExpiry} hari";

                    $alert = SecurityAlert::create([
                        'org_id' => $orgId,
                        'rule_code' => $ruleCode,
                        'severity' => $severity,
                        'title' => $titleText,
                        'description' => "Pastikan pembaruan DPA dilakukan sebelum kontrak berakhir.",
                        'module' => 'vendor-risk',
                        'record_id' => $vendor->id,
                        'metadata' => [
                            'vendor_name' => $vendor->name,
                            'dpa_expiry' => $dpaExpiry,
                            'days_remaining' => $daysUntilExpiry,
                        ],
                    ]);
                    $alerts[] = $alert;
                }
            }
        }
        return $alerts;
    }

    /**
     * Rule 4: High-risk ROPA without corresponding DPIA.
     */
    protected function checkRopaHighRiskNoDpia(string $orgId): array
    {
        $alerts = [];
        $highRiskRopas = Ropa::where('org_id', $orgId)
            ->where('risk_level', 'High')
            ->get();

        foreach ($highRiskRopas as $ropa) {
            // Check if DPIA exists linked to this ROPA
            $hasDpia = Dpia::where('org_id', $orgId)
                ->where('ropa_id', $ropa->id)
                ->exists();

            if (!$hasDpia) {
                $exists = SecurityAlert::where('org_id', $orgId)
                    ->where('rule_code', 'ropa_high_no_dpia')
                    ->where('record_id', $ropa->id)
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->exists();

                if (!$exists) {
                    $alert = SecurityAlert::create([
                        'org_id' => $orgId,
                        'rule_code' => 'ropa_high_no_dpia',
                        'severity' => 'high',
                        'title' => "ROPA berisiko tinggi tanpa DPIA",
                        'description' => "Aktivitas pemrosesan '{$ropa->processing_activity_name}' teridentifikasi berisiko tinggi namun belum memiliki DPIA terkait.",
                        'module' => 'ropa',
                        'record_id' => $ropa->id,
                        'metadata' => [
                            'processing_activity' => $ropa->processing_activity_name,
                        ],
                    ]);
                    $alerts[] = $alert;
                }
            }
        }
        return $alerts;
    }

    /**
     * Rule 5: Gap Assessment is stale (> 90 days without update).
     */
    protected function checkStaleGapAssessment(string $orgId): array
    {
        $alerts = [];
        $latestGap = \App\Models\GapAssessment::where('org_id', $orgId)
            ->orderBy('updated_at', 'desc')
            ->first();

        if ($latestGap) {
            $daysSince = Carbon::parse($latestGap->updated_at)->diffInDays(now());
            if ($daysSince > 90) {
                $exists = SecurityAlert::where('org_id', $orgId)
                    ->where('rule_code', 'gap_stale')
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->exists();

                if (!$exists) {
                    $alert = SecurityAlert::create([
                        'org_id' => $orgId,
                        'rule_code' => 'gap_stale',
                        'severity' => 'medium',
                        'title' => "Gap Assessment belum diperbarui ({$daysSince} hari)",
                        'description' => "Disarankan melakukan assessment ulang setiap 90 hari untuk menjaga akurasi compliance posture.",
                        'module' => 'gap-assessment',
                        'metadata' => [
                            'last_updated' => $latestGap->updated_at->toISOString(),
                            'days_since' => $daysSince,
                        ],
                    ]);
                    $alerts[] = $alert;
                }
            }
        }
        return $alerts;
    }
}
