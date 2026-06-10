<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiaAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'lia_code', 'title', 'description', 'processing_activity',
        'linked_ropa_id', 'linked_dpia_id',
        'legitimate_interest_basis', 'legitimate_interest_reason',
        'purpose_test', 'necessity_test', 'balancing_test',
        'balancing_risk_events',
        'subject_loses_control', 'subject_loses_control_reason',
        'conclusion_purpose', 'conclusion_necessity', 'conclusion_balancing', 'conclusion_notes',
        'maker_id', 'checker_id', 'approver_id',
        'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
        'overall_score', 'assessment_result', 'status',
        'wizard_data', 'created_by',
    ];

    protected $casts = [
        'purpose_test' => 'array',
        'necessity_test' => 'array',
        'balancing_test' => 'array',
        'balancing_risk_events' => 'array',
        'wizard_data' => 'array',
        'overall_score' => 'decimal:2',
        'is_locked' => 'boolean',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    // Status state machine
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const VERDICT_PASS = 'lulus';
    public const VERDICT_FAIL = 'tidak_lulus';

    /** Tiga uji LIA — urutan step wizard. */
    public const TESTS = ['purpose', 'necessity', 'balancing'];

    /**
     * Katalog pertanyaan panduan DEFAULT wizard LIA — single source of
     * truth (platform-level, tidak ada tabel). FE fetch lewat
     * GET /lia/questions, jangan hardcode.
     *
     * question_code = key jawaban di JSON test record LIA (purpose_test /
     * necessity_test) atau nama kolom dedicated (storage=column, khusus
     * balancing: balancing_risk_events + subject_loses_control) — 1:1
     * dengan key yang sudah tersimpan di record lama supaya data existing
     * tetap render.
     *
     * input_type:
     *   textarea      → jawaban free-text.
     *   yes_no        → tombol Ya/Tidak (+ alasan free-text di reason_code).
     *   choice        → pilihan tunggal dari options (+ alasan di reason_code).
     *   multi_select  → checkbox multi dari options (+ isian "Lainnya" di other_code).
     *   risk_register → komponen Risk Register (kolom balancing_risk_events).
     *
     * LIA KUALITATIF — tidak ada weight/scoring. Verdict per uji diputuskan
     * MANUAL oleh Approver (lulus/tidak_lulus); katalog ini murni pertanyaan
     * panduan. Label/description bisa di-override per org lewat
     * lia_question_overrides; test & input_type TIDAK bisa diubah.
     */
    public const DEFAULT_QUESTIONS = [
        // ── Uji Tujuan (Purpose Test) — jawaban di JSON purpose_test ──
        ['question_code' => 'why_needed', 'test' => 'purpose', 'input_type' => 'textarea', 'storage' => 'json',
            'label' => 'Mengapa pemrosesan dibutuhkan?',
            'description' => 'Jika data tidak diproses, apakah tujuan tetap bisa tercapai?',
            'reason_code' => null, 'other_code' => null, 'options' => null],
        ['question_code' => 'expected_benefit', 'test' => 'purpose', 'input_type' => 'textarea', 'storage' => 'json',
            'label' => 'Manfaat apa yang diharapkan?',
            'description' => 'Manfaat bagi organisasi dan/atau bagi subjek data...',
            'reason_code' => null, 'other_code' => null, 'options' => null],
        ['question_code' => 'benefit_categories', 'test' => 'purpose', 'input_type' => 'multi_select', 'storage' => 'json',
            'label' => 'Keuntungan yang diharapkan (multi-select)',
            'description' => null,
            'reason_code' => null, 'other_code' => 'benefit_other',
            'options' => [
                ['value' => 'operasional', 'label' => 'Operasional'],
                ['value' => 'bisnis', 'label' => 'Bisnis'],
                ['value' => 'keamanan', 'label' => 'Keamanan & Mitigasi Risiko'],
                ['value' => 'pelayanan', 'label' => 'Pelayanan Pengguna'],
                ['value' => 'pengembangan', 'label' => 'Pengembangan Sistem/Aplikasi'],
            ]],
        ['question_code' => 'importance', 'test' => 'purpose', 'input_type' => 'choice', 'storage' => 'json',
            'label' => 'Tingkat Kepentingan Keuntungan',
            'description' => null,
            'reason_code' => 'importance_reason', 'other_code' => null,
            'options' => [
                ['value' => 'high', 'label' => 'Tinggi'],
                ['value' => 'medium', 'label' => 'Sedang'],
                ['value' => 'low', 'label' => 'Rendah'],
            ]],
        ['question_code' => 'impact_if_skipped', 'test' => 'purpose', 'input_type' => 'textarea', 'storage' => 'json',
            'label' => 'Dampak jika pemrosesan tidak dilakukan?',
            'description' => null,
            'reason_code' => null, 'other_code' => null, 'options' => null],

        // ── Uji Kebutuhan (Necessity Test) — jawaban di JSON necessity_test ──
        ['question_code' => 'is_necessary', 'test' => 'necessity', 'input_type' => 'yes_no', 'storage' => 'json',
            'label' => 'Apakah pemrosesan ini diperlukan?',
            'description' => null,
            'reason_code' => 'is_necessary_reason', 'other_code' => null, 'options' => null],
        ['question_code' => 'is_proportional', 'test' => 'necessity', 'input_type' => 'yes_no', 'storage' => 'json',
            'label' => 'Apakah pemrosesan proporsional?',
            'description' => null,
            'reason_code' => 'is_proportional_reason', 'other_code' => null, 'options' => null],
        ['question_code' => 'can_avoid_personal', 'test' => 'necessity', 'input_type' => 'yes_no', 'storage' => 'json',
            'label' => 'Bisa dicapai tanpa data pribadi?',
            'description' => null,
            'reason_code' => null, 'other_code' => null, 'options' => null],
        ['question_code' => 'has_privacy_alternative', 'test' => 'necessity', 'input_type' => 'yes_no', 'storage' => 'json',
            'label' => 'Ada pendekatan lebih ramah privasi?',
            'description' => null,
            'reason_code' => 'privacy_alternative_reason', 'other_code' => null, 'options' => null],

        // ── Uji Keseimbangan (Balancing Test) — jawaban di kolom dedicated ──
        ['question_code' => 'risk_register', 'test' => 'balancing', 'input_type' => 'risk_register', 'storage' => 'column',
            'label' => 'Risk Register — Potensi peristiwa risiko terhadap subjek data',
            'description' => null,
            'reason_code' => null, 'other_code' => null, 'options' => null],
        ['question_code' => 'subject_loses_control', 'test' => 'balancing', 'input_type' => 'yes_no', 'storage' => 'column',
            'label' => 'Apakah subjek data dapat kehilangan kendali atas data pribadinya?',
            'description' => null,
            'reason_code' => 'subject_loses_control_reason', 'other_code' => null, 'options' => null],
    ];

    /**
     * Resolve set pertanyaan EFEKTIF untuk satu org (mirror
     * TiaAssessment::effectiveMetrics / MaturityQuestion::effectiveQuestions):
     *   1. Katalog default (DEFAULT_QUESTIONS) sebagai basis.
     *   2. Override per-org diterapkan: field non-null (label/description)
     *      menggantikan nilai default (test & input_type TIDAK pernah
     *      berubah); is_active=false → pertanyaan di-DROP (kecuali
     *      $includeInactive=true, untuk management UI).
     *   3. Custom questions (CustomLiaQuestion) org di-append (textarea,
     *      jawaban masuk JSON test terkait keyed by question_code).
     *
     * Setiap entri di-tag: is_default (bool), is_overridden (bool),
     * is_active (bool), is_custom (bool). Filter per test dilakukan
     * pemanggil (FE step wizard / review).
     *
     * TIDAK ada dampak scoring — murni menentukan pertanyaan yang tampil.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function effectiveQuestions(?string $orgId, bool $includeInactive = false): array
    {
        $defaults = [];
        foreach (self::DEFAULT_QUESTIONS as $i => $q) {
            $defaults[] = array_merge($q, [
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

        $overrides = LiaQuestionOverride::forOrg($orgId)
            ->get()
            ->keyBy('question_code');

        $effective = [];
        foreach ($defaults as $row) {
            $ov = $overrides->get($row['question_code']);
            if ($ov) {
                foreach (LiaQuestionOverride::OVERRIDABLE_FIELDS as $field) {
                    if ($ov->{$field} !== null) {
                        $row[$field] = $ov->{$field};
                        $row['is_overridden'] = true;
                    }
                }
                if (! $ov->is_active) {
                    if (! $includeInactive) {
                        continue; // pertanyaan default dinonaktifkan untuk org ini
                    }
                    $row['is_active'] = false;
                }
            }

            $effective[] = $row;
        }

        $customs = CustomLiaQuestion::forOrg($orgId)
            ->when(! $includeInactive, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->get()
            ->map(function ($cq) {
                $row = $cq->toQuestionFormat();
                $row['is_default'] = false;
                $row['is_overridden'] = false;
                $row['is_active'] = (bool) $cq->is_active;

                return $row;
            })
            ->toArray();

        return array_merge($effective, $customs);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    public function dpia()
    {
        return $this->belongsTo(\App\Models\Dpia::class, 'linked_dpia_id');
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

    /**
     * Whether the record is editable for the given user. Locked records
     * are read-only except for root performing an emergency unlock.
     */
    public function isEditableBy(?\App\Models\User $user): bool
    {
        if (!$user) return false;
        if (!$this->is_locked) return true;
        return $user->role === 'root';
    }

    /**
     * Compute the overall LIA verdict from the three test conclusions.
     * Returns 'lulus' only if all three are pass, otherwise 'tidak_lulus'.
     * Null kalau approver belum mengisi.
     */
    public function overallVerdict(): ?string
    {
        $verdicts = array_filter([
            $this->conclusion_purpose,
            $this->conclusion_necessity,
            $this->conclusion_balancing,
        ]);
        if (count($verdicts) < 3) return null;
        return collect($verdicts)->every(fn ($v) => $v === self::VERDICT_PASS)
            ? self::VERDICT_PASS
            : self::VERDICT_FAIL;
    }
}
