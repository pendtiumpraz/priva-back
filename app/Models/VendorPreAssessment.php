<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor Pre-Assessment (triage / "Penyaringan Lingkup PDP").
 *
 * Gate BEFORE the full vendor assessment. Short triage questionnaire whose
 * answers AUTO-SUGGEST the PDP scope (in_scope vs out_of_scope). A reviewer
 * confirms/overrides via /decide; OUT OF SCOPE needs DPO approval.
 *
 * Question catalog mirrors the LIA pattern: a platform-level DEFAULT const
 * + per-org overrides (triage_question_overrides) + custom questions
 * (custom_triage_questions), resolved by effectiveQuestions().
 */
class VendorPreAssessment extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'vendor_id',
        'answers',
        'suggested_scope',
        'final_scope',
        'justification',
        'overridden',
        'status',
        'filled_by',
        'decided_by',
        'decided_at',
        'approved_by',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'assessment_token',
        'token_expires_at',
        'token_consumed_at',
        'submitted_at',
        'submitted_ip',
        'submitted_user_agent',
    ];

    protected $casts = [
        'answers' => 'array',
        'overridden' => 'boolean',
        'decided_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    // Status lifecycle.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_DECIDED = 'decided';

    // Scope values (match Vendor::SCOPE_* for in/out).
    public const SCOPE_IN = 'in_scope';

    public const SCOPE_OUT = 'out_of_scope';

    public const FILLED_INTERNAL = 'internal';

    public const FILLED_PUBLIC = 'public_token';

    /**
     * Katalog pertanyaan triage DEFAULT — single source of truth
     * (platform-level, tidak ada tabel). FE fetch lewat
     * GET /third-party/triage-questions, jangan hardcode.
     *
     * Core questions (is_core=true) adalah pertanyaan DECISIVE: jika salah satu
     * dijawab 'ya' → scope auto-suggest 'in_scope'. Non-core murni informatif.
     *
     * Label/description/is_core bisa di-override per org lewat
     * triage_question_overrides; pertanyaan bisa dinonaktifkan (is_active=false).
     *
     * @var array<int, array{question_code:string,text:string,description:?string,is_core:bool}>
     */
    public const DEFAULT_QUESTIONS = [
        [
            'question_code' => 'akses_data_pribadi',
            'text' => 'Apakah pihak ketiga mengakses, memproses, atau menyimpan data pribadi milik kami atau subjek data kami?',
            'description' => 'Termasuk data pegawai, pelanggan, atau pihak lain yang dapat diidentifikasi.',
            'is_core' => true,
        ],
        [
            'question_code' => 'akses_sistem',
            'text' => 'Apakah pihak ketiga memiliki akses ke sistem/aplikasi kami yang berisi data pribadi?',
            'description' => 'Akses langsung maupun tidak langsung (mis. remote support, integrasi API, hak admin).',
            'is_core' => true,
        ],
        [
            'question_code' => 'data_spesifik',
            'text' => 'Apakah pihak ketiga menerima/memproses data pribadi yang bersifat spesifik/sensitif?',
            'description' => 'Data kesehatan, biometrik, keuangan, anak, agama, atau data spesifik lain menurut UU PDP.',
            'is_core' => true,
        ],
        [
            'question_code' => 'transfer_luar_negeri',
            'text' => 'Apakah pemrosesan melibatkan transfer data pribadi ke luar wilayah Indonesia?',
            'description' => 'Termasuk penyimpanan pada server / cloud yang berlokasi di luar negeri.',
            'is_core' => true,
        ],
        [
            'question_code' => 'subkontrak',
            'text' => 'Apakah pihak ketiga dapat melibatkan subkontraktor/pihak keempat dalam pemrosesan?',
            'description' => 'Informasi pendukung — tidak menentukan lingkup secara langsung.',
            'is_core' => false,
        ],
    ];

    // =============================================
    // Relationships + scopes
    // =============================================

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    // =============================================
    // Effective question set (default + override + custom)
    // =============================================

    /**
     * Resolve set pertanyaan triage EFEKTIF untuk satu org (mirror
     * LiaAssessment::effectiveQuestions):
     *   1. Katalog default (DEFAULT_QUESTIONS) sebagai basis.
     *   2. Override per-org: field non-null (text/description/is_core)
     *      menggantikan default; is_active=false → DROP (kecuali
     *      $includeInactive untuk management UI).
     *   3. Custom questions (CustomTriageQuestion) di-append.
     *
     * Tiap entri di-tag: is_default, is_overridden, is_active, is_custom,
     * is_core, sort_order.
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

        $overrides = TriageQuestionOverride::forOrg($orgId)
            ->get()
            ->keyBy('question_code');

        $effective = [];
        foreach ($defaults as $row) {
            $ov = $overrides->get($row['question_code']);
            if ($ov) {
                foreach (TriageQuestionOverride::OVERRIDABLE_FIELDS as $field) {
                    if ($ov->{$field} !== null) {
                        $row[$field] = $field === 'is_core' ? (bool) $ov->{$field} : $ov->{$field};
                        $row['is_overridden'] = true;
                    }
                }
                if (! $ov->is_active) {
                    if (! $includeInactive) {
                        continue;
                    }
                    $row['is_active'] = false;
                }
            }

            $effective[] = $row;
        }

        $customs = CustomTriageQuestion::forOrg($orgId)
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

    // =============================================
    // Scope suggestion logic
    // =============================================

    /**
     * Auto-suggest scope dari jawaban triage:
     *   - Ambil set pertanyaan EFEKTIF org → kumpulkan CORE question codes.
     *   - Jika ADA core question yang dijawab 'ya' → 'in_scope'.
     *   - Jika SEMUA core question dijawab 'tidak' → 'out_of_scope'.
     *   - Jika tidak ada core question yang dijawab sama sekali → 'in_scope'
     *     (konservatif — lebih baik over-screen daripada lewatkan).
     *
     * @param  array<string, string|null>  $answers  map question_code => 'ya'|'tidak'|null
     */
    public static function suggestScope(array $answers, ?string $orgId): string
    {
        $effective = self::effectiveQuestions($orgId);
        $coreCodes = collect($effective)
            ->filter(fn ($q) => ! empty($q['is_core']))
            ->pluck('question_code')
            ->all();

        $anyCoreAnswered = false;
        foreach ($coreCodes as $code) {
            $ans = $answers[$code] ?? null;
            if ($ans === 'ya') {
                return self::SCOPE_IN;
            }
            if ($ans === 'tidak') {
                $anyCoreAnswered = true;
            }
        }

        // Tidak ada core question yang dijawab → konservatif in_scope.
        if (! $anyCoreAnswered) {
            return self::SCOPE_IN;
        }

        return self::SCOPE_OUT;
    }
}
