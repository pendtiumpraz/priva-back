<?php

namespace App\Services;

use App\Models\ConsentCollectionPoint;
use App\Models\DsrRequest;
use App\Models\LiaAssessment;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

/**
 * Policy Generator — Auto-Fill (Fase 2).
 *
 * Deterministically pulls a tenant's existing module data (RoPA / LIA / Consent
 * / TPRM / TIA / DSR / Org profile) and produces a pre-filled `wizard_inputs`
 * shape (60-80% of a privacy policy's inputs) with a per-field SOURCE tag so the
 * user can review/confirm each element before it is drafted by
 * PolicyGeneratorService::generate. No LLM call here — the values are grounded
 * in real tenant records (auditable, no hallucination); the AI drafting happens
 * downstream from the confirmed inputs.
 *
 * Every query is filtered by org_id EXPLICITLY (defense-in-depth) because some
 * source models (e.g. ConsentCollectionPoint) do NOT carry the BelongsToOrg
 * global scope.
 */
class PolicyAutofillService
{
    /**
     * @return array{wizard_inputs:array<string,mixed>, sources:array<string,array{source:string,confidence:string,count?:int}>, coverage_estimate:array{filled:int,applicable:int}}
     */
    public function prefill(string $orgId, string $audience = 'customer'): array
    {
        $inputs = [];
        $sources = [];

        $set = function (string $key, $value, string $source, string $confidence, ?int $count = null) use (&$inputs, &$sources) {
            $filled = is_array($value) ? ! empty($value) : ($value !== null && $value !== '');
            $inputs[$key] = $value;
            $sources[$key] = array_filter([
                'source' => $source,
                'confidence' => $filled ? $confidence : 'absent',
                'count' => $count,
            ], fn ($v) => $v !== null);
        };

        $naKeys = PolicyElementValidator::AUDIENCE_NOT_APPLICABLE[$audience] ?? [];

        // --- Element 1/2: controller identity + DPO (Organization profile) ---
        $org = $this->safe(fn () => Organization::find($orgId), null);
        $set('company_name', $org?->name, 'Profil Organisasi', 'direct');
        $set('company_address', $org?->address, 'Profil Organisasi', 'direct');
        $set('company_email', $org?->email, 'Profil Organisasi', 'direct');
        $set('company_phone', $org?->phone, 'Profil Organisasi', 'direct');
        $set('company_website', $org?->website, 'Profil Organisasi', 'direct');
        // DPO: org only stores a has_dpo flag — the contact itself is manual input.
        $set('dpo_contact', $org?->has_dpo ? '[Lengkapi kontak DPO]' : null, 'Profil Organisasi (flag has_dpo) — kontak manual', 'partial');

        // --- Elements 3/4/5/6/13: RoPA ---
        $ropa = $this->fromRopa($orgId);
        $set('data_categories', $ropa['data_categories'], 'RoPA', 'direct', count($ropa['data_categories']));
        $set('purposes', $ropa['purposes'], 'RoPA + LIA', 'direct', count($ropa['purposes']));
        $set('legal_basis', $ropa['legal_basis'], 'RoPA (Pasal 20)', 'direct', count($ropa['legal_basis']));
        $set('retention', $ropa['retention'], 'RoPA (retensi)', 'direct', count($ropa['retention']));

        // Purposes enriched by LIA (legitimate-interest activities).
        $lia = $this->fromLia($orgId);
        if (! empty($lia)) {
            $inputs['purposes'] = array_values(array_unique(array_merge($inputs['purposes'] ?? [], $lia)));
            $sources['purposes'] = ['source' => 'RoPA + LIA', 'confidence' => 'direct', 'count' => count($inputs['purposes'])];
        }

        // --- Element 7: third parties (TPRM / Vendor) ---
        $vendors = $this->fromVendors($orgId);
        $set('third_parties', $vendors, 'TPRM (Vendor)', 'direct', count($vendors));

        // --- Element 8: data subject rights (DSR — static type list + SLA) ---
        $set('data_subject_rights', $this->dsrRights(), 'DSR (tipe hak + SLA 72 jam)', 'static');

        // --- Element 9: withdraw consent (Consent collection points) ---
        $consent = $this->fromConsent($orgId);
        $set('consent_withdrawal', $consent['withdrawal'], 'Consent Management', empty($consent['withdrawal']) ? 'absent' : 'direct');

        // --- Element 13: cross-border (TIA + RoPA destination) ---
        $crossBorder = array_values(array_unique(array_merge($this->fromTia($orgId), $ropa['cross_border'])));
        $set('cross_border', $crossBorder, 'TIA + RoPA', 'direct', count($crossBorder));

        // --- Element 14: breach notification (static Pasal 46 — not stored as editable text) ---
        $set('breach_notification', $this->breachStatic(), 'Statik (Pasal 46 UU PDP) — lihat modul Breach', 'static');

        // --- Element 10: security measures (no ISMS module — static template) ---
        $set('security_measures', $this->securityStatic(), 'Statik (template) — sesuaikan kontrol ISMS', 'static');

        // --- Element 15: policy update mechanism (static + version control) ---
        $set('policy_update', 'Kebijakan ini dapat diperbarui sewaktu-waktu; perubahan material akan diberitahukan dan nomor versi diperbarui.', 'Statik (version control)', 'static');

        // --- Audience-conditional elements 11/12 ---
        if (! in_array('cookie', $naKeys, true)) {
            $set('cookie_policy', $consent['has_cookie_banner'] ? 'Situs menggunakan cookie/kuki untuk fungsi dan analitik (lihat banner consent).' : null, 'Consent Management (cookie banner)', $consent['has_cookie_banner'] ? 'partial' : 'absent');
        }
        if (! in_array('data_anak', $naKeys, true)) {
            $childHint = $this->childDataHint($org);
            $set('child_data', $childHint, 'Profil Organisasi (jenis subjek data)', $childHint ? 'partial' : 'absent');
        }

        $filled = collect($sources)->filter(fn ($s) => ($s['confidence'] ?? 'absent') !== 'absent')->count();

        return [
            'wizard_inputs' => $inputs,
            'sources' => $sources,
            'coverage_estimate' => ['filled' => $filled, 'applicable' => count($sources)],
        ];
    }

    /**
     * Stable fingerprint of the tenant's source-module data for an audience.
     * Used by version-diff: if the fingerprint changes after a policy was
     * generated, the source data (e.g. RoPA) changed → the policy is stale.
     */
    public function sourceFingerprint(string $orgId, string $audience = 'customer'): string
    {
        $prefill = $this->prefill($orgId, $audience);

        return sha1((string) json_encode($prefill['wizard_inputs']));
    }

    /** @return array{data_categories:array,purposes:array,legal_basis:array,retention:array,cross_border:array} */
    private function fromRopa(string $orgId): array
    {
        $rows = $this->safe(fn () => Ropa::withoutGlobalScope('org')->where('org_id', $orgId)->get(), collect());

        $cats = [];
        $purposes = [];
        $legal = [];
        $retention = [];
        $cross = [];

        foreach ($rows as $r) {
            $wiz = is_array($r->wizard_data) ? $r->wizard_data : [];
            $pengumpulan = $wiz['pengumpulan_data'] ?? [];
            $info = $wiz['informasi_pemrosesan'] ?? [];
            $kirim = $wiz['pengiriman_data'] ?? [];

            $cats = array_merge(
                $cats,
                (array) ($r->data_categories ?? []),
                (array) ($pengumpulan['jenis_data'] ?? []),
                (array) ($pengumpulan['jenis_data_umum'] ?? []),
                (array) ($pengumpulan['jenis_data_pii'] ?? []),
                (array) ($pengumpulan['jenis_data_spesifik'] ?? []),
            );

            foreach ([$r->purpose ?? null, $info['purpose'] ?? null, $info['tujuan'] ?? null] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $purposes[] = trim($p);
                }
            }
            foreach ([$r->legal_basis ?? null, $info['legal_basis'] ?? null] as $lb) {
                if (is_string($lb) && trim($lb) !== '') {
                    $legal[] = $this->legalBasisLabel(trim($lb));
                }
            }
            if (is_string($r->retention_period ?? null) && trim($r->retention_period) !== '') {
                $retention[] = trim($r->retention_period);
            }
            $dest = $kirim['negara_tujuan'] ?? null;
            foreach ((array) $dest as $country) {
                if (is_string($country) && trim($country) !== '') {
                    $cross[] = trim($country);
                }
            }
        }

        return [
            'data_categories' => $this->cleanList($cats),
            'purposes' => $this->cleanList($purposes),
            'legal_basis' => $this->cleanList($legal),
            'retention' => $this->cleanList($retention),
            'cross_border' => $this->cleanList($cross),
        ];
    }

    /** @return array<int,string> */
    private function fromLia(string $orgId): array
    {
        $rows = $this->safe(fn () => LiaAssessment::withoutGlobalScope('org')->where('org_id', $orgId)->get(), collect());

        return $this->cleanList($rows->map(fn ($l) => $l->processing_activity)->all());
    }

    /** @return array<int,array{name:string,service:?string,data_shared:?string,country:?string}> */
    private function fromVendors(string $orgId): array
    {
        // Vendor has no 'org' global scope, so isolation rests on the explicit where.
        $rows = $this->safe(fn () => Vendor::where('org_id', $orgId)->get(), collect());

        return $rows->map(fn ($v) => [
            'name' => (string) $v->name,
            'service' => $this->stringify($v->services_provided),
            'data_shared' => $this->stringify($v->data_shared),
            'country' => $v->country,
        ])->values()->all();
    }

    /** @return array{withdrawal:array<int,array{point:string,url:?string}>,has_cookie_banner:bool} */
    private function fromConsent(string $orgId): array
    {
        // ConsentCollectionPoint has NO BelongsToOrg scope → explicit org filter is mandatory.
        $points = $this->safe(fn () => ConsentCollectionPoint::where('org_id', $orgId)->get(), collect());

        $withdrawal = $points->map(fn ($p) => [
            'point' => (string) $p->name,
            'url' => $p->redirect_url ?? null,
        ])->values()->all();

        $hasCookie = $points->contains(fn ($p) => ($p->kind ?? null) === 'cookie_banner');

        return ['withdrawal' => $withdrawal, 'has_cookie_banner' => $hasCookie];
    }

    /** @return array<int,string> */
    private function fromTia(string $orgId): array
    {
        $rows = $this->safe(fn () => TiaAssessment::withoutGlobalScope('org')->where('org_id', $orgId)->get(), collect());

        return $this->cleanList($rows->map(fn ($t) => $t->destination_country)->all());
    }

    /** @return array{types:array<int,string>,sla:string} */
    private function dsrRights(): array
    {
        $types = defined(DsrRequest::class.'::REQUEST_TYPES') ? DsrRequest::REQUEST_TYPES : [
            'access', 'rectification', 'erasure', 'portability', 'restriction', 'objection', 'withdraw_consent',
        ];

        return ['types' => array_values((array) $types), 'sla' => '72 jam (3 hari kerja)'];
    }

    private function breachStatic(): string
    {
        return 'Pelanggaran data pribadi akan diberitahukan kepada KOMDIGI dan subjek data terdampak '
            .'paling lambat 3x24 jam (Pasal 46 UU PDP). Lihat SOP Breach Response organisasi untuk prosedur lengkap.';
    }

    private function securityStatic(): string
    {
        return 'Langkah keamanan teknis & organisasional: enkripsi data at-rest/in-transit, kontrol akses berbasis peran, '
            .'audit log, pelatihan personel, dan evaluasi keamanan berkala (Pasal 35-39 UU PDP). [Sesuaikan dengan kontrol ISMS organisasi].';
    }

    private function childDataHint(?Organization $org): ?string
    {
        $subjects = $org?->data_subjects_type;
        $blob = mb_strtolower(is_array($subjects) ? implode(' ', $subjects) : (string) $subjects);
        if ($blob !== '' && (str_contains($blob, 'anak') || str_contains($blob, 'child') || str_contains($blob, 'minor'))) {
            return 'Organisasi memproses data anak — wajib persetujuan orang tua/wali (Pasal 26 UU PDP + Permenkominfo 20/2016).';
        }

        return null;
    }

    // --- helpers ---

    /** Translate a RoPA legal-basis slug into a Pasal 20 label; pass through unknown values. */
    private function legalBasisLabel(string $slug): string
    {
        return match (strtolower($slug)) {
            'kontrak' => 'Pelaksanaan kontrak (Pasal 20)',
            'langkah_pra_kontrak' => 'Langkah pra-kontrak atas permintaan subjek (Pasal 20)',
            'persetujuan', 'consent' => 'Persetujuan subjek data (Pasal 20)',
            'kewajiban_hukum' => 'Pemenuhan kewajiban hukum (Pasal 20)',
            'kepentingan_sah' => 'Kepentingan sah / legitimate interest (Pasal 20)',
            'vital_interest', 'kepentingan_vital' => 'Pelindungan kepentingan vital (Pasal 20)',
            'kepentingan_umum' => 'Pelaksanaan tugas kepentingan publik / kewenangan otoritas (Pasal 20)',
            default => $slug,
        };
    }

    private function stringify($value): ?string
    {
        if (is_array($value)) {
            $flat = array_filter(array_map('strval', $value), fn ($v) => trim($v) !== '');

            return empty($flat) ? null : implode(', ', $flat);
        }

        return (is_string($value) && trim($value) !== '') ? $value : null;
    }

    /** @return array<int,string> */
    private function cleanList(array $items): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($i) => is_string($i) ? trim($i) : null, $items),
            fn ($i) => $i !== null && $i !== '',
        )));
    }

    /** Run a query defensively; never let one missing module break the whole prefill. */
    private function safe(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('PolicyAutofill: source query failed: '.$e->getMessage());

            return $fallback;
        }
    }
}
