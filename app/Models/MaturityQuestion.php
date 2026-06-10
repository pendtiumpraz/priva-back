<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Master list of Maturity Assessment questions. Platform-level
 * (no org_id) — same 18 questions apply to every tenant. Versioned
 * via the `version` column so a UU PDP amendment can ship as v2
 * without affecting in-flight assessments.
 *
 * Pinned to landlord because all tenants share the same question set.
 */
class MaturityQuestion extends Model
{
    use HasUuids, LandlordPinned;

    public const DOMAIN_GOVERNANCE = 'governance';
    public const DOMAIN_PROCESSING_BASIS = 'processing_basis';
    public const DOMAIN_CONTROLLER_OBLIGATIONS = 'controller_obligations';
    public const DOMAIN_SECURITY = 'security';

    public const ALL_DOMAINS = [
        self::DOMAIN_GOVERNANCE,
        self::DOMAIN_PROCESSING_BASIS,
        self::DOMAIN_CONTROLLER_OBLIGATIONS,
        self::DOMAIN_SECURITY,
    ];

    public const DOMAIN_LABELS = [
        self::DOMAIN_GOVERNANCE              => 'Tata Kelola & Penunjukan DPO',
        self::DOMAIN_PROCESSING_BASIS        => 'Dasar Pemrosesan & Hak Subjek Data',
        self::DOMAIN_CONTROLLER_OBLIGATIONS  => 'Kewajiban Pengendali & Prosesor Data',
        self::DOMAIN_SECURITY                => 'Keamanan & Penanganan Kegagalan',
    ];

    protected $fillable = [
        'question_code', 'domain', 'regulation_ref',
        'question_text', 'description', 'scoring_guide',
        'is_active', 'sort_order', 'version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'scoring_guide' => 'array',
    ];

    /**
     * Resolve set pertanyaan EFEKTIF untuk satu org (mirror
     * GapAssessment::effectiveQuestions):
     *   1. Default platform (maturity_questions, is_active, per version)
     *      sebagai basis.
     *   2. Override per-org diterapkan: field non-null menggantikan nilai
     *      default (domain TIDAK pernah berubah); is_active=false →
     *      pertanyaan di-DROP (kecuali $includeInactive=true, untuk
     *      management UI).
     *   3. Custom questions (CustomMaturityQuestion) org di-append.
     *
     * Setiap entri di-tag: is_default (bool), is_overridden (bool),
     * is_active (bool), dan is_custom untuk custom questions.
     *
     * Dipakai oleh endpoint questions(), validasi response, DAN
     * MaturityAssessment::recompute() supaya pertanyaan default yang
     * dinonaktifkan tidak ikut dihitung dan pertanyaan custom ikut masuk
     * rata-rata domain + overall.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function effectiveQuestions(?string $orgId, string $version = 'v1', bool $includeInactive = false): array
    {
        $defaults = self::query()
            ->withoutGlobalScope('org')   // platform-level
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if (! $orgId) {
            return $defaults->map(function ($q) {
                $row = $q->toArray();
                $row['is_default'] = true;
                $row['is_overridden'] = false;
                $row['is_active'] = true;
                $row['is_custom'] = false;

                return $row;
            })->values()->all();
        }

        $overrides = MaturityQuestionOverride::forOrg($orgId)
            ->get()
            ->keyBy('question_code');

        $effective = [];
        foreach ($defaults as $q) {
            $row = $q->toArray();
            $row['is_default'] = true;
            $row['is_overridden'] = false;
            $row['is_active'] = true;
            $row['is_custom'] = false;

            $ov = $overrides->get($q->question_code);
            if ($ov) {
                foreach (MaturityQuestionOverride::OVERRIDABLE_TEXT_FIELDS as $field) {
                    if ($ov->{$field} !== null) {
                        $row[$field] = $ov->{$field};
                        $row['is_overridden'] = true;
                    }
                }
                if ($ov->scoring_guide !== null) {
                    $row['scoring_guide'] = $ov->scoring_guide;
                    $row['is_overridden'] = true;
                }
                if (! $ov->is_active) {
                    if (! $includeInactive) {
                        continue; // default dinonaktifkan untuk org ini
                    }
                    $row['is_active'] = false;
                }
            }

            $effective[] = $row;
        }

        $customs = CustomMaturityQuestion::forOrg($orgId)
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
}
