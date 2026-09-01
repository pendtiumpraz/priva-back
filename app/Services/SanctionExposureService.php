<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\Dpia;
use App\Models\Ropa;
use App\Support\SanctionRegistry;

/**
 * Paparan (exposure) sanksi administratif per tenant.
 *
 * Menggabungkan daftar kewajiban ber-sanksi (PP 33/2026 Pasal 184) dengan
 * state platform nyata: kewajiban ber-`signal` dinilai at_risk/ok, sisanya
 * ditandai `monitor` (perlu ditinjau manual). Menutup gap audit: skor
 * kepatuhan sebelumnya tidak pernah ditautkan ke pasal ber-sanksi.
 */
class SanctionExposureService
{
    public function __construct(private PpdpObligationService $ppdp) {}

    /**
     * @return array<string,mixed>
     */
    public function summaryFor(?string $orgId): array
    {
        $signals = $orgId ? $this->computeSignals($orgId) : [];

        $obligations = array_map(function (array $o) use ($signals) {
            $status = 'monitor';
            $detail = null;
            if (! empty($o['signal']) && isset($signals[$o['signal']])) {
                $status = $signals[$o['signal']]['status'];
                $detail = $signals[$o['signal']]['detail'];
            }
            $o['status'] = $status;
            $o['detail'] = $detail;
            $o['category_label'] = SanctionRegistry::CATEGORY_LABELS[$o['category']] ?? $o['category'];

            return $o;
        }, SanctionRegistry::obligations());

        $atRisk = array_values(array_filter($obligations, fn ($o) => $o['status'] === 'at_risk'));

        return [
            'obligations' => $obligations,
            'summary' => [
                'total' => count($obligations),
                'at_risk' => count($atRisk),
                'ok' => count(array_filter($obligations, fn ($o) => $o['status'] === 'ok')),
                'monitor' => count(array_filter($obligations, fn ($o) => $o['status'] === 'monitor')),
            ],
            'at_risk' => $atRisk,
            'sanction_types' => SanctionRegistry::SANCTION_TYPES,
            'fine' => [
                'max_percent' => SanctionRegistry::FINE_MAX_PERCENT,
                'basis' => SanctionRegistry::FINE_BASIS,
                'variables' => SanctionRegistry::FINE_VARIABLES,
            ],
            'category_labels' => SanctionRegistry::CATEGORY_LABELS,
        ];
    }

    /**
     * Hitung sinyal paparan dari state platform.
     *
     * @return array<string,array{status:string,detail:?string}>
     */
    private function computeSignals(string $orgId): array
    {
        $ropaQ = fn () => Ropa::withoutGlobalScope('org')->where('org_id', $orgId);

        // Pasal 74(1) — perekaman/RoPA.
        $ropaCount = $ropaQ()->count();
        $records = $ropaCount === 0
            ? ['status' => 'at_risk', 'detail' => 'Belum ada RoPA (perekaman kegiatan pemrosesan).']
            : ['status' => 'ok', 'detail' => "{$ropaCount} RoPA tercatat."];

        // Pasal 80(1) — pengakhiran saat masa retensi tercapai.
        $overdueRetensi = $ropaQ()
            ->whereNotNull('retention_due_date')
            ->where('status', '!=', 'draft')
            ->whereDate('retention_due_date', '<', now())
            ->count();
        $retention = $overdueRetensi > 0
            ? ['status' => 'at_risk', 'detail' => "{$overdueRetensi} RoPA telah melewati masa retensi & belum diakhiri."]
            : ['status' => 'ok', 'detail' => 'Tidak ada RoPA yang melewati masa retensi.'];

        // Pasal 114(1) — notifikasi kegagalan 3×24 jam.
        $overdueBreach = BreachIncident::withoutGlobalScope('org')->where('org_id', $orgId)
            ->where('notification_required', true)
            ->whereNotNull('notification_deadline')
            ->where('notification_deadline', '<', now())
            ->where(function ($q) {
                $q->whereNull('notified_komdigi_at')->orWhereNull('notified_subjects_at');
            })
            ->count();
        $breach = $overdueBreach > 0
            ? ['status' => 'at_risk', 'detail' => "{$overdueBreach} insiden melewati tenggat notifikasi 3×24 jam & belum lengkap dinotifikasi."]
            : ['status' => 'ok', 'detail' => 'Tidak ada notifikasi kegagalan yang terlambat.'];

        // Pasal 120(1) — DPIA untuk pemrosesan risiko tinggi.
        $highRisk = $ropaQ()->where('risk_level', 'high')->count();
        $dpiaCount = Dpia::withoutGlobalScope('org')->where('org_id', $orgId)->count();
        $dpia = ($highRisk > 0 && $dpiaCount === 0)
            ? ['status' => 'at_risk', 'detail' => "{$highRisk} RoPA berisiko tinggi namun belum ada DPIA."]
            : ['status' => 'ok', 'detail' => $highRisk === 0 ? 'Belum ada pemrosesan risiko tinggi.' : "{$dpiaCount} DPIA tersedia."];

        // Pasal 142(1) — penunjukan PPDP.
        $ob = $this->ppdp->evaluate($orgId);
        if ($ob['gap']) {
            $ppdp = ['status' => 'at_risk', 'detail' => 'Wajib menunjuk PPDP namun belum ada penunjukan aktif.'];
        } elseif ($ob['has_active_ppdp']) {
            $ppdp = ['status' => 'ok', 'detail' => 'PPDP aktif tersedia.'];
        } else {
            $ppdp = ['status' => 'monitor', 'detail' => 'Penunjukan PPDP belum wajib berdasarkan asesmen pemicu.'];
        }

        return [
            'ropa_records' => $records,
            'retention_end' => $retention,
            'breach_notification' => $breach,
            'dpia' => $dpia,
            'ppdp_appointment' => $ppdp,
        ];
    }
}
