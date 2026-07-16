<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaturityAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'title', 'description', 'version', 'dimensions', 'overall_level', 'overall_score',
        'recommendations', 'status', 'created_by',
        'input_method', 'domain_scores', 'uploaded_doc_ids',
        'submitted_at', 'submitted_by', 'auto_derived_at', 'auto_derive_metadata',
        'attachments', 'ai_analyses',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'recommendations' => 'array',
        'attachments' => 'array',
        'ai_analyses' => 'array',
        'overall_level' => 'integer',
        'overall_score' => 'decimal:2',
        'domain_scores' => 'array',
        'uploaded_doc_ids' => 'array',
        'auto_derive_metadata' => 'array',
        'submitted_at' => 'datetime',
        'auto_derived_at' => 'datetime',
    ];

    public const INPUT_QUESTIONNAIRE = 'questionnaire';
    public const INPUT_DOCUMENT = 'document';
    public const INPUT_AUTO_DERIVE = 'auto_derive';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PUBLISHED = 'published';

    public const LEVEL_AD_HOC = 1;
    public const LEVEL_DEFINED = 2;
    public const LEVEL_MANAGED = 3;
    public const LEVEL_OPTIMIZED = 4;

    public const LEVEL_LABELS = [
        self::LEVEL_AD_HOC    => 'Ad-hoc',
        self::LEVEL_DEFINED   => 'Defined',
        self::LEVEL_MANAGED   => 'Managed',
        self::LEVEL_OPTIMIZED => 'Optimized',
    ];

    public function responses()
    {
        return $this->hasMany(MaturityQuestionResponse::class, 'assessment_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Map an overall_score (0-10 range) to one of the 4 maturity levels
     * per PDF spec: 1-3 ad-hoc, 4-6 defined, 7-8 managed, 9-10 optimized.
     */
    public static function scoreToLevel(float $score): int
    {
        if ($score >= 9) return self::LEVEL_OPTIMIZED;
        if ($score >= 7) return self::LEVEL_MANAGED;
        if ($score >= 4) return self::LEVEL_DEFINED;
        return self::LEVEL_AD_HOC;
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->overall_level] ?? 'Ad-hoc';
    }

    /**
     * Agregasi hasil AI analysis untuk satu pertanyaan ke 1 verdict
     * (mirror GapAssessment::aggregateAiVerdict). Banyak dokumen per
     * pertanyaan → pilih WORST status (paling konservatif). `unsure`
     * di-skip; kalau semua unsure return null.
     *
     * PENTING: di Maturity verdict ini ADVISORY ONLY — tidak pernah
     * meng-override skor slider 1-10 user dan tidak dipakai di
     * recompute(). UI menampilkannya sebagai badge per pertanyaan.
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
     * Compute domain_scores + overall_score from the responses() collection.
     * Called after every save in the controller.
     *
     * Hanya response yang question_code-nya ada di set pertanyaan EFEKTIF
     * org (default aktif + custom aktif) yang ikut dihitung — response
     * lama milik pertanyaan default yang dinonaktifkan otomatis di-exclude.
     * Domain pertanyaan custom mengikuti pilihan domain saat ini (dari
     * effective set, bukan kolom domain yang tersimpan di response).
     */
    public function recompute(): void
    {
        $domainByCode = collect(MaturityQuestion::effectiveQuestions($this->org_id, $this->version ?? 'v1'))
            ->pluck('domain', 'question_code');

        $responses = $this->responses()->get()
            ->filter(fn ($r) => $domainByCode->has($r->question_code));

        $byDomain = $responses->groupBy(fn ($r) => $domainByCode[$r->question_code]);
        // Domain = union 4 default + domain lain di set efektif — pertanyaan
        // custom boleh memakai domain BARU; domain baru otomatis jadi key
        // baru di domain_scores (FE menampilkan key yang ada, bukan
        // hardcode 4 domain default).
        $domains = collect(MaturityQuestion::ALL_DOMAINS)
            ->concat($domainByCode->values())
            ->filter()
            ->unique()
            ->values();
        $domainScores = [];
        foreach ($domains as $d) {
            $items = $byDomain->get($d, collect());
            $domainScores[$d] = $items->isEmpty() ? null : round($items->avg('score'), 2);
        }
        $this->domain_scores = $domainScores;

        $allScores = $responses->pluck('score');
        if ($allScores->isNotEmpty()) {
            $this->overall_score = round($allScores->avg(), 2);
            $this->overall_level = self::scoreToLevel((float) $this->overall_score);
        }
    }

    public const DIMENSIONS = ['governance', 'process', 'technology', 'people', 'compliance'];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
