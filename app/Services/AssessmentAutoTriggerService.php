<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CrossBorderTransfer;
use App\Models\LiaAssessment;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint X4 — Auto-trigger draft assessments from upstream records.
 *
 * Convention: every trigger is wrapped in try/catch by the caller so a
 * failure here can never roll back the upstream create. We log warnings
 * and return null instead of throwing.
 *
 * Triggers:
 *   - ROPA dengan legal_basis = legitimate interest → draft LIA
 *   - CrossBorderTransfer create → draft TIA (always — every transfer
 *     needs an impact assessment per UU PDP Pasal 56)
 *   - Vendor dengan risk_level high/critical → draft TIA (data
 *     processor agreement risiko transfer perlu di-assess)
 */
class AssessmentAutoTriggerService
{
    /**
     * Indonesian + English variants we treat as legitimate-interest.
     * Match is case-insensitive and matches anywhere in the string so
     * wizard_data text and free-form values both register.
     */
    public const LEGITIMATE_INTEREST_TOKENS = [
        'legitimate_interest',
        'legitimate interest',
        'kepentingan_sah',
        'kepentingan sah',
    ];

    public function fromRopa(Ropa $ropa, ?string $createdBy = null): ?LiaAssessment
    {
        $basis = strtolower((string) ($ropa->legal_basis ?? ''));
        $isLegitimate = collect(self::LEGITIMATE_INTEREST_TOKENS)
            ->contains(fn ($token) => str_contains($basis, $token));

        if (!$isLegitimate) return null;

        // Don't double-trigger if a draft LIA already exists for this RoPA.
        $existing = LiaAssessment::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $ropa->org_id)
            ->where('linked_ropa_id', $ropa->id)
            ->first();
        if ($existing) return null;

        try {
            $unit = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ropa->division ?? $ropa->work_unit ?? 'GEN'), 0, 4));
            $activity = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ropa->processing_activity ?? 'ACT'), 0, 4));
            $existingCount = LiaAssessment::query()->withoutGlobalScope('org')
                ->where('org_id', $ropa->org_id)
                ->where('lia_code', 'like', "LIA-{$unit}-{$activity}-%")
                ->count();
            $code = sprintf('LIA-%s-%s-%02d', $unit, $activity, $existingCount + 1);

            $snapshot = [];
            foreach (\App\Http\Controllers\Api\LiaController::ROPA_AUTOFILL_FIELDS as $f) {
                if (isset($ropa->$f)) $snapshot[$f] = $ropa->$f;
            }

            $lia = LiaAssessment::create([
                'org_id' => $ropa->org_id,
                'lia_code' => $code,
                'title' => 'LIA — ' . ($ropa->processing_activity ?? 'Aktivitas Pemrosesan'),
                'processing_activity' => $ropa->processing_activity,
                'linked_ropa_id' => $ropa->id,
                'created_by' => $createdBy ?: $ropa->created_by,
                'status' => LiaAssessment::STATUS_DRAFT,
                'wizard_data' => [
                    'ropa_snapshot' => $snapshot,
                    'ropa_id' => $ropa->id,
                    'snapshot_taken_at' => now()->toIso8601String(),
                    'auto_triggered' => true,
                    'trigger_reason' => 'ROPA legal_basis = ' . ($ropa->legal_basis ?? 'unknown'),
                ],
            ]);

            AuditLog::log('lia', $lia->id, 'auto_created_from_ropa', [
                'lia_code' => $lia->lia_code,
                'ropa_id' => $ropa->id,
                'legal_basis' => $ropa->legal_basis,
            ], 'system');

            return $lia;
        } catch (Throwable $e) {
            Log::warning('Auto-LIA from ROPA failed (non-fatal)', [
                'ropa_id' => $ropa->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cross-border transfer registered → seed a draft TIA.
     */
    public function fromCrossBorder(CrossBorderTransfer $cbt, ?string $createdBy = null): ?TiaAssessment
    {
        $existing = TiaAssessment::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $cbt->org_id)
            ->where('linked_cross_border_id', $cbt->id)
            ->first();
        if ($existing) return null;

        try {
            $existingCount = TiaAssessment::query()->withoutGlobalScope('org')
                ->where('org_id', $cbt->org_id)->count();
            $code = sprintf('TIA-%s-%03d', date('Y'), $existingCount + 1);

            // Delegate prefill to TiaAssessment so explicit "Buat TIA"
            // and auto-trigger paths produce identical drafts.
            $prefill = TiaAssessment::buildPrefillFromCrossBorder($cbt);

            $tia = TiaAssessment::create(array_merge($prefill, [
                'tia_code' => $code,
                'title' => 'TIA — Transfer ke ' . ($cbt->destination_country ?? 'Unknown'),
                'status' => TiaAssessment::STATUS_DRAFT,
                'created_by' => $createdBy ?: ($cbt->created_by ?? null),
                'wizard_data' => array_merge($prefill['wizard_data'], [
                    'auto_triggered' => true,
                    'trigger_source' => 'cross_border',
                ]),
            ]));

            AuditLog::log('tia', $tia->id, 'auto_created_from_cross_border', [
                'tia_code' => $tia->tia_code,
                'cross_border_id' => $cbt->id,
                'destination_country' => $cbt->destination_country,
                'adequacy_tier' => $prefill['wizard_data']['adequacy_tier'] ?? null,
            ], 'system');

            return $tia;
        } catch (Throwable $e) {
            Log::warning('Auto-TIA from CrossBorder failed (non-fatal)', [
                'cross_border_id' => $cbt->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * High/critical-risk vendor or cross-border vendor → seed a draft TIA.
     */
    public function fromVendor(Vendor $vendor, ?string $createdBy = null): ?TiaAssessment
    {
        $riskLevel = strtolower((string) ($vendor->risk_level ?? ''));
        $isHighRisk = in_array($riskLevel, ['high', 'critical'], true);
        $isOffshore = !empty($vendor->country) && strcasecmp((string) $vendor->country, 'Indonesia') !== 0;

        if (!$isHighRisk && !$isOffshore) return null;

        $existing = TiaAssessment::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $vendor->org_id)
            ->where('linked_vendor_id', $vendor->id)
            ->first();
        if ($existing) return null;

        try {
            $existingCount = TiaAssessment::query()->withoutGlobalScope('org')
                ->where('org_id', $vendor->org_id)->count();
            $code = sprintf('TIA-%s-%03d', date('Y'), $existingCount + 1);

            $reason = $isHighRisk
                ? "Vendor risk_level={$riskLevel}"
                : "Vendor offshore (country={$vendor->country})";

            $tia = TiaAssessment::create([
                'org_id' => $vendor->org_id,
                'tia_code' => $code,
                'title' => 'TIA — Vendor ' . ($vendor->name ?? 'Unknown'),
                'linked_vendor_id' => $vendor->id,
                'destination_country' => $vendor->country ?? null,
                'status' => TiaAssessment::STATUS_DRAFT,
                'created_by' => $createdBy ?: ($vendor->created_by ?? null),
                'wizard_data' => [
                    'auto_triggered' => true,
                    'trigger_source' => 'vendor',
                    'trigger_reason' => $reason,
                    'vendor_id' => $vendor->id,
                    'snapshot_taken_at' => now()->toIso8601String(),
                ],
            ]);

            AuditLog::log('tia', $tia->id, 'auto_created_from_vendor', [
                'tia_code' => $tia->tia_code,
                'vendor_id' => $vendor->id,
                'reason' => $reason,
            ], 'system');

            return $tia;
        } catch (Throwable $e) {
            Log::warning('Auto-TIA from Vendor failed (non-fatal)', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
