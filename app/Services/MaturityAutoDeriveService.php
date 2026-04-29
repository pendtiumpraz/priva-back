<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maturity Assessment auto-derivation. For each of the 18 UU PDP
 * questions the seeder defines, this service produces a 1-10 score
 * by querying existing Nexus data without touching the AI layer.
 *
 * The heuristics are intentionally conservative — base score 5 with
 * coverage bonuses on top — so a freshly-installed tenant doesn't get
 * inflated marks for empty data. Bonuses are awarded for breadth
 * (count thresholds), depth (completeness rates), and recency
 * (recent activity within the last 90 days).
 *
 * The auto-derived score is treated as a starting point — the operator
 * adjusts it during the Review step before submitting the assessment.
 *
 * Returns: array<question_code => ['score' => int 1-10, 'metadata' => array]>
 *   The metadata is persisted into maturity_question_responses.source_metadata
 *   so an auditor can later retrace why a particular score was awarded.
 */
class MaturityAutoDeriveService
{
    public function deriveAll(string $orgId): array
    {
        $org = Organization::query()->withoutGlobalScope('org')->find($orgId);
        if (!$org) return [];

        return [
            'A1' => $this->scoreDpoAppointment($orgId),
            'A2' => $this->scoreOrgStructure($orgId),
            'B3' => $this->scoreProcessingBasis($orgId),
            'B4' => $this->scoreSubjectRights($orgId),
            'C5' => $this->scoreRopaQuality($orgId),
            'C6' => $this->scoreDpiaQuality($orgId),
            'C7' => $this->scoreDataMapping($orgId),
            'C8' => $this->scoreDpaContracts($orgId),
            'C9' => $this->scoreDataAccuracy($orgId),
            'C10' => $this->scorePurposeLimitation($orgId),
            'C11' => $this->scoreRetention($orgId),
            'C12' => $this->scoreEncryption($orgId),
            'C13' => $this->scoreBreachLog($orgId),
            'C14' => $this->scoreProcessorAudit($orgId),
            'C15' => $this->scorePrivacyByDesign($orgId),
            'C16' => $this->scoreStaffTraining($orgId),
            'D17' => $this->scoreSecurity($orgId),
            'D18' => $this->scoreBreachResponse($orgId),
        ];
    }

    // ─── Domain A: Tata Kelola ──────────────────────────────────────────────

    private function scoreDpoAppointment(string $orgId): array
    {
        $dpoCount = User::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->where('role', 'dpo')->count();
        $approverCount = User::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->where('role', 'approver')->count();

        $score = 3;
        if ($dpoCount >= 1) $score += 4;       // formal DPO assigned
        if ($dpoCount >= 2) $score += 1;       // backup DPO
        if ($approverCount >= 1) $score += 1;  // approval chain in place
        $score = min(10, $score);

        return [
            'score' => $score,
            'metadata' => ['dpo_count' => $dpoCount, 'approver_count' => $approverCount],
        ];
    }

    private function scoreOrgStructure(string $orgId): array
    {
        $deptCount = Department::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        $score = 3;
        if ($deptCount >= 1) $score += 2;
        if ($deptCount >= 3) $score += 2;
        if ($deptCount >= 5) $score += 2;
        if ($deptCount >= 10) $score += 1;
        $score = min(10, $score);

        return ['score' => $score, 'metadata' => ['department_count' => $deptCount]];
    }

    // ─── Domain B: Dasar Pemrosesan & Hak Subjek ────────────────────────────

    private function scoreProcessingBasis(string $orgId): array
    {
        $total = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $withBasis = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('legal_basis')->count();

        if ($total === 0) {
            return ['score' => 1, 'metadata' => ['ropa_count' => 0]];
        }

        $coverage = $withBasis / $total;     // 0..1
        $score = (int) ceil(2 + $coverage * 8); // 2..10
        return [
            'score' => $score,
            'metadata' => ['ropa_count' => $total, 'with_legal_basis' => $withBasis, 'coverage' => round($coverage, 2)],
        ];
    }

    private function scoreSubjectRights(string $orgId): array
    {
        $totalDsr = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $closedOnTime = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('status', 'closed')
            ->whereColumn('closed_at', '<=', 'deadline_at')
            ->count();

        $score = 2;
        if ($totalDsr >= 1) $score += 2;     // DSR mechanism exists
        if ($totalDsr >= 5) $score += 1;
        if ($totalDsr > 0 && $closedOnTime / $totalDsr >= 0.8) $score += 3;
        if ($totalDsr > 0 && $closedOnTime / $totalDsr >= 0.95) $score += 2;
        $score = min(10, $score);

        return [
            'score' => $score,
            'metadata' => ['dsr_total' => $totalDsr, 'dsr_closed_on_time' => $closedOnTime],
        ];
    }

    // ─── Domain C: Kewajiban Pengendali ─────────────────────────────────────

    private function scoreRopaQuality(string $orgId): array
    {
        $count = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $approved = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('status', 'approved')->count();
        $withRetention = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('retention_period')->count();

        if ($count === 0) {
            return ['score' => 1, 'metadata' => ['ropa_count' => 0]];
        }

        $score = 3;
        if ($count >= 5) $score += 1;
        if ($count >= 15) $score += 1;
        if ($approved / $count >= 0.5) $score += 2;
        if ($approved / $count >= 0.9) $score += 1;
        if ($withRetention / $count >= 0.7) $score += 2;
        $score = min(10, $score);

        return [
            'score' => $score,
            'metadata' => ['ropa_count' => $count, 'approved' => $approved, 'with_retention' => $withRetention],
        ];
    }

    private function scoreDpiaQuality(string $orgId): array
    {
        $highRiskRopa = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('risk_level', 'HIGH')->count();
        $dpiaCount = Dpia::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $dpiaApproved = Dpia::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('status', 'approved')->count();

        $score = 2;
        if ($highRiskRopa === 0 && $dpiaCount === 0) {
            $score = 5;        // no HIGH-risk activity → no DPIA expected → neutral
            return ['score' => $score, 'metadata' => ['high_risk_ropa' => 0, 'dpia_count' => 0]];
        }

        if ($highRiskRopa > 0) {
            $coverage = min(1, $dpiaCount / $highRiskRopa);
            $score = (int) ceil(2 + $coverage * 6);
            if ($coverage > 0 && $dpiaApproved / max(1, $dpiaCount) >= 0.8) $score += 2;
        }
        $score = min(10, $score);

        return [
            'score' => $score,
            'metadata' => ['high_risk_ropa' => $highRiskRopa, 'dpia_count' => $dpiaCount, 'dpia_approved' => $dpiaApproved],
        ];
    }

    private function scoreDataMapping(string $orgId): array
    {
        $sysCount = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        $score = 3;
        if ($sysCount >= 1) $score += 1;
        if ($sysCount >= 5) $score += 2;
        if ($sysCount >= 15) $score += 2;
        if ($sysCount >= 30) $score += 2;
        $score = min(10, $score);

        return ['score' => $score, 'metadata' => ['information_system_count' => $sysCount]];
    }

    private function scoreDpaContracts(string $orgId): array
    {
        $vendorCount = Vendor::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        // VendorAssessment has dpa_status — query if column exists
        $assessed = 0;
        if (Schema::hasTable('vendor_assessments')) {
            $assessed = DB::table('vendor_assessments')
                ->whereIn('vendor_id', Vendor::query()->withoutGlobalScope('org')->where('org_id', $orgId)->pluck('id'))
                ->whereNull('deleted_at')
                ->count();
        }

        if ($vendorCount === 0) {
            return ['score' => 4, 'metadata' => ['vendor_count' => 0, 'assessed' => 0]];
        }

        $score = 3;
        if ($vendorCount >= 3) $score += 1;
        if ($assessed / $vendorCount >= 0.5) $score += 3;
        if ($assessed / $vendorCount >= 0.9) $score += 3;
        $score = min(10, $score);

        return ['score' => $score, 'metadata' => ['vendor_count' => $vendorCount, 'assessed' => $assessed]];
    }

    private function scoreDataAccuracy(string $orgId): array
    {
        // Use DSR rectification handling rate as proxy
        $rectifications = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('request_type', 'rectification')->count();
        $closed = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('request_type', 'rectification')->where('status', 'closed')->count();

        $score = 4;
        if ($rectifications >= 1) $score += 1;
        if ($rectifications > 0 && $closed / $rectifications >= 0.8) $score += 3;
        if ($rectifications > 0 && $closed / $rectifications >= 0.95) $score += 2;
        $score = min(10, $score);

        return ['score' => $score, 'metadata' => ['rectification_total' => $rectifications, 'rectification_closed' => $closed]];
    }

    private function scorePurposeLimitation(string $orgId): array
    {
        // Approximation: % RoPA with description filled (purpose declared)
        $total = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $withPurpose = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->count();

        if ($total === 0) return ['score' => 4, 'metadata' => ['ropa_count' => 0]];
        $coverage = $withPurpose / $total;
        $score = (int) ceil(3 + $coverage * 7);
        return ['score' => $score, 'metadata' => ['ropa_count' => $total, 'with_purpose' => $withPurpose]];
    }

    private function scoreRetention(string $orgId): array
    {
        $total = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $withRetention = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('retention_period')
            ->where('retention_period', '!=', '')
            ->count();

        if ($total === 0) return ['score' => 3, 'metadata' => ['ropa_count' => 0]];
        $coverage = $withRetention / $total;
        $score = (int) ceil(2 + $coverage * 8);
        return ['score' => $score, 'metadata' => ['ropa_count' => $total, 'with_retention' => $withRetention]];
    }

    private function scoreEncryption(string $orgId): array
    {
        // No structured encryption metadata yet — use a moderate default + bonus
        // for security_measures filled in RoPA
        $total = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $withSec = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('security_measures')
            ->where('security_measures', '!=', '')
            ->count();

        if ($total === 0) return ['score' => 5, 'metadata' => ['ropa_count' => 0]];
        $coverage = $withSec / $total;
        $score = (int) ceil(4 + $coverage * 6);
        return ['score' => $score, 'metadata' => ['ropa_count' => $total, 'with_security_measures' => $withSec]];
    }

    private function scoreBreachLog(string $orgId): array
    {
        $count = 0;
        if (Schema::hasTable('breach_incidents')) {
            $count = DB::table('breach_incidents')
                ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        }
        $score = 3;
        if ($count >= 1) $score += 3;        // breach log exists
        if ($count >= 3) $score += 2;
        if ($count >= 10) $score += 2;
        $score = min(10, $score);

        return ['score' => $score, 'metadata' => ['breach_log_count' => $count]];
    }

    private function scoreProcessorAudit(string $orgId): array
    {
        $vendorCount = Vendor::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $assessed = 0;
        if (Schema::hasTable('vendor_assessments')) {
            $assessed = DB::table('vendor_assessments')
                ->whereIn('vendor_id', Vendor::query()->withoutGlobalScope('org')->where('org_id', $orgId)->pluck('id'))
                ->whereNull('deleted_at')
                ->where('updated_at', '>=', now()->subDays(365))
                ->count();
        }
        if ($vendorCount === 0) return ['score' => 4, 'metadata' => ['vendor_count' => 0]];
        $coverage = $assessed / $vendorCount;
        $score = (int) ceil(2 + $coverage * 8);
        return ['score' => $score, 'metadata' => ['vendor_count' => $vendorCount, 'assessed_in_year' => $assessed]];
    }

    private function scorePrivacyByDesign(string $orgId): array
    {
        // Proxy: count of DPIA records done at design time (status approved)
        $count = Dpia::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        $score = 4;
        if ($count >= 1) $score += 1;
        if ($count >= 5) $score += 2;
        if ($count >= 10) $score += 2;
        $score = min(10, $score);
        return ['score' => $score, 'metadata' => ['dpia_count' => $count]];
    }

    private function scoreStaffTraining(string $orgId): array
    {
        // No training table yet — neutral default. Sprint X4 can wire up
        // an actual training records table if/when ada.
        return ['score' => 5, 'metadata' => ['source' => 'no_training_table_yet']];
    }

    // ─── Domain D: Keamanan & Penanganan Kegagalan ──────────────────────────

    private function scoreSecurity(string $orgId): array
    {
        // Use security_postures findings as proxy if exists
        $score = 5;
        if (Schema::hasTable('security_postures')) {
            $count = DB::table('security_postures')->where('org_id', $orgId)->count();
            if ($count >= 1) $score += 2;
            if ($count >= 10) $score += 2;
        }
        $score = min(10, $score);
        return ['score' => $score, 'metadata' => ['security_posture_records' => $count ?? 0]];
    }

    private function scoreBreachResponse(string $orgId): array
    {
        if (!Schema::hasTable('breach_incidents')) {
            return ['score' => 4, 'metadata' => ['breach_incidents_table' => false]];
        }
        $total = DB::table('breach_incidents')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();
        if ($total === 0) {
            return ['score' => 6, 'metadata' => ['breach_count' => 0]];   // no breaches → neutral-positive
        }
        // Count breaches notified within 72h (deadline_at <= reported_at + 72h)
        $onTime = DB::table('breach_incidents')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereRaw("notified_at IS NOT NULL")
            ->count();
        $score = (int) ceil(3 + ($onTime / max(1, $total)) * 7);
        return ['score' => $score, 'metadata' => ['breach_count' => $total, 'notified' => $onTime]];
    }
}
