<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TiaAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'tia_code', 'title', 'description',
        'linked_cross_border_id', 'linked_ropa_id', 'linked_vendor_id',
        'transfer_details', 'legal_framework', 'risk_assessment', 'supplementary_measures',
        'transfer_volume', 'transfer_frequency', 'transfer_basis', 'transfer_basis_other',
        'destination_country', 'destination_has_pdp_law', 'destination_has_pdp_authority',
        'recipient_maturity_score', 'sender_maturity_score',
        'risk_regulation_mismatch', 'risk_contractual_breach', 'risk_admin_sanctions',
        'risk_data_leak', 'risk_data_integrity', 'risk_sovereign_access',
        'security_protocol_score', 'security_encryption_score',
        'overall_risk_score', 'overall_risk_level',
        'supplementary_doc_ids',
        'maker_id', 'checker_id', 'approver_id',
        'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
        'conclusion_verdict', 'conclusion_notes',
        'status', 'wizard_data', 'created_by',
        'attachments', 'ai_analyses', 'score_adjustments',
        'answer_notes',
    ];

    protected $casts = [
        'transfer_details' => 'array',
        'legal_framework' => 'array',
        'risk_assessment' => 'array',
        'supplementary_measures' => 'array',
        'wizard_data' => 'array',
        'attachments' => 'array',
        'ai_analyses' => 'array',
        'score_adjustments' => 'array',
        'supplementary_doc_ids' => 'array',
        'answer_notes' => 'array',
        'destination_has_pdp_law' => 'boolean',
        'destination_has_pdp_authority' => 'boolean',
        'is_locked' => 'boolean',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'overall_risk_score' => 'decimal:2',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const VERDICT_APPROVED    = 'approved';      // safe to transfer
    public const VERDICT_CONDITIONAL = 'conditional';   // safe with mitigations
    public const VERDICT_REJECTED    = 'rejected';      // unsafe — block transfer

    /**
     * 6 risk metric keys (high score = risky). Used by computeOverallRisk()
     * and by the frontend MaturityRuler with `inverted=true`.
     */
    public const RISK_METRIC_KEYS = [
        'risk_regulation_mismatch',
        'risk_contractual_breach',
        'risk_admin_sanctions',
        'risk_data_leak',
        'risk_data_integrity',
        'risk_sovereign_access',
    ];

    /**
     * 2 security metric keys (high score = good — provides mitigation).
     */
    public const SECURITY_METRIC_KEYS = [
        'security_protocol_score',
        'security_encryption_score',
    ];

    /**
     * Katalog metrik DEFAULT TIA — single source of truth (platform-level,
     * tidak ada tabel). FE fetch lewat GET /tia/metrics, jangan hardcode.
     *
     * metric_code = nama kolom skor di tia_assessments (1:1 mapping).
     * kind: 'risk'  → skor 10 = paling berisiko (masuk komponen risiko);
     *       'security' → skor 10 = paling aman (masuk komponen mitigasi).
     * Bobot default semua metrik = 1 (bisa di-override per org lewat
     * tia_metric_overrides).
     */
    public const DEFAULT_METRICS = [
        ['metric_code' => 'risk_regulation_mismatch', 'kind' => 'risk',
            'label' => 'Ketidaksesuaian Standar Perlindungan',
            'description' => 'Negara tujuan tidak punya regulasi/proteksi setara UU PDP. Skor 10 = sangat berisiko.'],
        ['metric_code' => 'risk_contractual_breach', 'kind' => 'risk',
            'label' => 'Potensi Pelanggaran Kontraktual',
            'description' => 'Kemungkinan pihak penerima melanggar klausul DPA/kontrak transfer.'],
        ['metric_code' => 'risk_admin_sanctions', 'kind' => 'risk',
            'label' => 'Potensi Sanksi Administrasi',
            'description' => 'Kemungkinan otoritas (OJK / Kominfo) menjatuhkan sanksi administrasi.'],
        ['metric_code' => 'risk_data_leak', 'kind' => 'risk',
            'label' => 'Kemungkinan Kebocoran Data',
            'description' => 'Probabilitas data leak di pihak penerima.'],
        ['metric_code' => 'risk_data_integrity', 'kind' => 'risk',
            'label' => 'Risiko Integritas Data',
            'description' => 'Risiko data berubah/rusak/tidak akurat selama transfer atau di pihak penerima.'],
        ['metric_code' => 'risk_sovereign_access', 'kind' => 'risk',
            'label' => 'Risiko Kedaulatan & Akses Pemerintah',
            'description' => 'Akses pemerintah negara tujuan ke data (subpoena, surveillance law).'],
        ['metric_code' => 'security_protocol_score', 'kind' => 'security',
            'label' => 'Implementasi Protokol Aman Antar Jaringan',
            'description' => 'TLS 1.3, mTLS, VPN, IPsec atau setara. Skor 10 = enforcement penuh + rotasi kredensial.'],
        ['metric_code' => 'security_encryption_score', 'kind' => 'security',
            'label' => 'Enkripsi, Anonimisasi, Pseudonimisasi',
            'description' => 'AES-256 at-rest + in-transit, anonimisasi sebelum transfer. Skor 10 = paling aman.'],
    ];

    /**
     * Resolve set metrik EFEKTIF untuk satu org (mirror
     * MaturityQuestion::effectiveQuestions):
     *   1. Katalog default (DEFAULT_METRICS) sebagai basis, weight = 1.
     *   2. Override per-org diterapkan: field non-null (label/description/
     *      weight) menggantikan nilai default (kind TIDAK pernah berubah);
     *      is_active=false → metrik di-DROP (kecuali $includeInactive=true,
     *      untuk management UI).
     *   3. Custom metrics (CustomTiaMetric) org di-append.
     *
     * Setiap entri di-tag: is_default (bool), is_overridden (bool),
     * is_active (bool), is_custom (bool).
     *
     * Dipakai oleh endpoint GET /tia/metrics, validasi submit, DAN
     * computeOverallRisk() supaya metrik default yang dinonaktifkan tidak
     * ikut dihitung dan metrik custom masuk weighted average.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function effectiveMetrics(?string $orgId, bool $includeInactive = false): array
    {
        $defaults = [];
        foreach (self::DEFAULT_METRICS as $i => $m) {
            $defaults[] = array_merge($m, [
                'weight' => 1.0,
                'sort_order' => $i,
                'is_default' => true,
                'is_overridden' => false,
                'is_active' => true,
                'is_custom' => false,
            ]);
        }

        if (! $orgId) {
            return $defaults;
        }

        $overrides = TiaMetricOverride::forOrg($orgId)
            ->get()
            ->keyBy('metric_code');

        $effective = [];
        foreach ($defaults as $row) {
            $ov = $overrides->get($row['metric_code']);
            if ($ov) {
                foreach (TiaMetricOverride::OVERRIDABLE_FIELDS as $field) {
                    if ($ov->{$field} !== null) {
                        $row[$field] = $field === 'weight' ? (float) $ov->weight : $ov->{$field};
                        $row['is_overridden'] = true;
                    }
                }
                if (! $ov->is_active) {
                    if (! $includeInactive) {
                        continue; // metrik default dinonaktifkan untuk org ini
                    }
                    $row['is_active'] = false;
                }
            }

            $effective[] = $row;
        }

        $customs = CustomTiaMetric::forOrg($orgId)
            ->when(! $includeInactive, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->get()
            ->map(function ($cm) {
                $row = $cm->toMetricFormat();
                $row['is_default'] = false;
                $row['is_overridden'] = false;
                $row['is_active'] = (bool) $cm->is_active;

                return $row;
            })
            ->toArray();

        return array_merge($effective, $customs);
    }

    /**
     * Skor metrik CUSTOM record ini, keyed by metric_code (CUST-N → int).
     * Disimpan di JSON `risk_assessment.custom_metric_scores` (skor metrik
     * default tetap di kolom dedicated masing-masing).
     *
     * @return array<string, int>
     */
    public function customMetricScores(): array
    {
        $scores = $this->risk_assessment['custom_metric_scores'] ?? [];

        return is_array($scores) ? $scores : [];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function crossBorder()
    {
        return $this->belongsTo(CrossBorderTransfer::class, 'linked_cross_border_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'linked_vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function maker()
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checker_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isEditableBy(?User $user): bool
    {
        if (!$user) return false;
        if (!$this->is_locked) return true;
        return $user->role === 'root';
    }

    /**
     * Agregasi hasil AI analysis untuk satu metrik ke 1 verdict (mirror
     * MaturityAssessment::aggregateAiVerdict). Banyak dokumen per metrik
     * → pilih WORST status (paling konservatif). `unsure` di-skip; kalau
     * semua unsure return null.
     *
     * PENTING: di TIA verdict ini ADVISORY ONLY — tidak pernah mengubah
     * skor metrik 1-10 user dan TIDAK dipakai di computeOverallRisk().
     * UI menampilkannya sebagai badge per metrik.
     *
     * @param  mixed  $value  Bisa null, single object (legacy), atau array of objects.
     */
    public static function aggregateAiVerdict(mixed $value): ?string
    {
        if (empty($value) || ! is_array($value)) return null;
        // Legacy single object
        $entries = isset($value['status']) ? [$value] : array_values($value);
        $rank = ['non_comply' => 3, 'partial' => 2, 'comply' => 1];
        $worst = null;
        $worstRank = 0;
        foreach ($entries as $e) {
            $st = is_array($e) ? ($e['status'] ?? null) : null;
            if (! $st || $st === 'unsure') continue;
            $r = $rank[$st] ?? 0;
            if ($r > $worstRank) {
                $worst = $st;
                $worstRank = $r;
            }
        }
        return $worst;
    }

    /**
     * Build the standard CBDT-derived prefill payload. Used by both the
     * explicit "Buat TIA" button (TiaController::fromCrossBorder) AND
     * the auto-trigger that fires on CrossBorder create
     * (AssessmentAutoTriggerService::fromCrossBorder) — single source of
     * truth so the two paths can't drift.
     *
     * Caller is responsible for tia_code, title, status, maker_id /
     * created_by — those vary by trigger source.
     *
     * @param  CrossBorderTransfer  $cbt
     * @return array<string, mixed>
     */
    public static function buildPrefillFromCrossBorder(CrossBorderTransfer $cbt): array
    {
        $adequacy = $cbt->adequacy();

        $volumeRisk = match ($cbt->transfer_volume_band) {
            'mass'   => 9,
            'large'  => 7,
            'medium' => 5,
            'small'  => 3,
            default  => null,
        };
        $sensitivityRisk = match ($cbt->data_sensitivity) {
            'extra_sensitive'    => 9,
            'sensitive_specific' => 7,
            'personal'           => 5,
            'general'            => 2,
            default              => null,
        };
        $protocolScore   = $cbt->encryption_in_transit ? 7 : 3;
        $encryptionScore = $cbt->encryption_at_rest    ? 7 : 3;

        return [
            'org_id' => $cbt->org_id,
            'linked_cross_border_id' => $cbt->id,
            'linked_ropa_id' => $cbt->linked_ropa_id,
            'destination_country' => $cbt->destination_country,
            'destination_has_pdp_law' => $adequacy?->has_pdp_law,
            'destination_has_pdp_authority' => $adequacy?->has_pdp_authority,
            'transfer_basis' => $cbt->legal_basis,
            'transfer_volume' => $cbt->transfer_volume_band,
            'transfer_frequency' => $cbt->transfer_frequency,
            'transfer_details' => [
                'destination_entity' => $cbt->destination_entity,
                'transfer_purpose' => $cbt->transfer_purpose,
                'data_categories' => $cbt->data_categories,
                'data_sensitivity' => $cbt->data_sensitivity,
                'transfer_mechanism' => $cbt->transfer_mechanism,
                'retention_period_days' => $cbt->retention_period_days,
                'recipient_dpo_name' => $cbt->recipient_dpo_name,
                'recipient_dpo_email' => $cbt->recipient_dpo_email,
            ],
            'risk_regulation_mismatch' => $adequacy?->default_regulation_mismatch,
            'risk_sovereign_access'    => $adequacy?->default_sovereign_access_risk,
            'risk_admin_sanctions'     => $adequacy?->default_admin_sanctions,
            'risk_data_leak'           => $volumeRisk,
            'risk_data_integrity'      => $sensitivityRisk,
            'security_protocol_score'   => $protocolScore,
            'security_encryption_score' => $encryptionScore,
            'wizard_data' => [
                'cross_border_id' => $cbt->id,
                'snapshot_taken_at' => now()->toIso8601String(),
                'adequacy_tier' => $adequacy?->tier,
                'adequacy_basis' => $adequacy?->basis,
                'cross_border_snapshot' => [
                    'destination_country' => $cbt->destination_country,
                    'destination_entity' => $cbt->destination_entity,
                    'transfer_purpose' => $cbt->transfer_purpose,
                    'legal_basis' => $cbt->legal_basis,
                    'data_categories' => $cbt->data_categories,
                    'transfer_volume_band' => $cbt->transfer_volume_band,
                    'transfer_frequency' => $cbt->transfer_frequency,
                    'data_sensitivity' => $cbt->data_sensitivity,
                    'transfer_mechanism' => $cbt->transfer_mechanism,
                    'encryption_in_transit' => $cbt->encryption_in_transit,
                    'encryption_at_rest' => $cbt->encryption_at_rest,
                    'data_minimization_applied' => $cbt->data_minimization_applied,
                    'retention_period_days' => $cbt->retention_period_days,
                    'recipient_dpo_name' => $cbt->recipient_dpo_name,
                    'recipient_dpo_email' => $cbt->recipient_dpo_email,
                ],
            ],
        ];
    }

    /**
     * Compute overall risk score on a 1-10 scale, weight-aware over the
     * EFFECTIVE metric set (default − disabled + custom, per org):
     *   - risk_component     = sum(score × weight) / sum(weight) atas
     *     metrik risk aktif (weighted average → raw risk)
     *   - security_component = weighted average metrik security aktif yang
     *     terisi → mitigation factor (1-10 → 0-1)
     *   - residual = risk_component × (1 − security_component/10 × 0.5)
     *     i.e. perfect security halves the raw risk; no security leaves it
     *     unchanged.
     *
     * Dengan semua metrik default aktif pada bobot 1 (tanpa custom),
     * hasilnya IDENTIK dengan formula lama avg(6 risk) × (1 − avg(2
     * security)/10 × 0.5).
     *
     * Guards:
     *   - semua metrik risk dinonaktifkan → 0.0 (tidak ada risiko terukur)
     *   - semua metrik security dinonaktifkan/kosong → mitigation 0
     *   - ada metrik risk aktif yang belum diskor → null (perilaku lama)
     */
    public function computeOverallRisk(): ?float
    {
        $metrics = self::effectiveMetrics($this->org_id);
        $customScores = $this->customMetricScores();

        $riskSum = 0.0;
        $riskWeight = 0.0;
        $secSum = 0.0;
        $secWeight = 0.0;
        $hasActiveRiskMetric = false;
        $missingRiskScore = false;

        foreach ($metrics as $m) {
            $value = ! empty($m['is_custom'])
                ? ($customScores[$m['metric_code']] ?? null)
                : $this->{$m['metric_code']};
            $weight = (float) ($m['weight'] ?? 1);

            if (($m['kind'] ?? 'risk') === 'risk') {
                $hasActiveRiskMetric = true;
                if ($value === null) {
                    $missingRiskScore = true;
                    continue;
                }
                $riskSum += (float) $value * $weight;
                $riskWeight += $weight;
            } else {
                if ($value === null) {
                    continue;
                }
                $secSum += (float) $value * $weight;
                $secWeight += $weight;
            }
        }

        if (! $hasActiveRiskMetric) return 0.0;
        if ($missingRiskScore) return null;

        $riskComponent = $riskWeight > 0 ? $riskSum / $riskWeight : 0.0;
        $securityComponent = $secWeight > 0 ? $secSum / $secWeight : 0.0;
        $residual = $riskComponent * (1 - $securityComponent / 10 * 0.5);

        return round((float) $residual, 2);
    }

    /**
     * Map the overall risk score to a level string for badging.
     */
    public function riskLevel(): ?string
    {
        $score = $this->computeOverallRisk();
        if ($score === null) return null;
        if ($score >= 7) return 'high';
        if ($score >= 4) return 'medium';
        return 'low';
    }
}
