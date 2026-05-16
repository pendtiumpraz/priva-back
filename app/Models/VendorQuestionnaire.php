<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level vendor questionnaire bank. Pinned to landlord because
 * the same question set applies to every tenant — they're regulatory /
 * industry-standard questions, not tenant preferences.
 *
 * Categories supported in v1 (covers ~85% of FI vendor scenarios):
 *   - cloud_infrastructure (AWS, GCP, Azure, Alibaba Cloud, Lintasarta)
 *   - saas                 (Salesforce, HubSpot, Zoom, Mailchimp)
 *   - data_processor       (KYC vendors, call center BPOs, payment processors)
 *
 * Future v1 expansion candidates: agency, sub_processor, professional_services.
 */
class VendorQuestionnaire extends Model
{
    use HasUuids, LandlordPinned;

    public const CATEGORY_CLOUD = 'cloud_infrastructure';
    public const CATEGORY_SAAS = 'saas';
    public const CATEGORY_DATA_PROCESSOR = 'data_processor';
    /**
     * Default category untuk semua pihak ketiga sejak Sprint G revisi:
     * 56 pertanyaan PDP komprehensif yang berlaku untuk SEMUA bidang vendor
     * (IT, Legal, HR, Procurement, dst), bukan hanya IT. Tenant boleh
     * disable/edit per kolom via customization endpoint.
     */
    public const CATEGORY_PDP_COMPLIANCE = 'pdp_compliance';

    public const ALL_CATEGORIES = [
        self::CATEGORY_PDP_COMPLIANCE,
        self::CATEGORY_CLOUD,
        self::CATEGORY_SAAS,
        self::CATEGORY_DATA_PROCESSOR,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_PDP_COMPLIANCE => 'Kepatuhan PDP — Pihak Ketiga (Default)',
        self::CATEGORY_CLOUD => 'Cloud Infrastructure / IaaS',
        self::CATEGORY_SAAS => 'SaaS Application',
        self::CATEGORY_DATA_PROCESSOR => 'Data Processor (manual handling)',
    ];

    public const CATEGORY_DESCRIPTIONS = [
        self::CATEGORY_PDP_COMPLIANCE => 'Default untuk semua pihak ketiga — 56 pertanyaan komprehensif tata kelola, operasi, SDM, dan teknologi sesuai UU PDP. Berlaku lintas bidang.',
        self::CATEGORY_CLOUD => 'Penyedia infrastruktur (AWS, GCP, Azure, Alibaba Cloud, Lintasarta) — host data atau aplikasi.',
        self::CATEGORY_SAAS => 'Aplikasi software-as-a-service (Salesforce, HubSpot, Zoom, Mailchimp) — proses data via fitur produk.',
        self::CATEGORY_DATA_PROCESSOR => 'Pihak ketiga yang memproses data subjek atas nama pengendali (KYC vendor, call center BPO, payment processor).',
    ];

    public const SECTION_GOVERNANCE = 'governance';
    public const SECTION_SECURITY = 'security';
    public const SECTION_DATA_HANDLING = 'data_handling';
    public const SECTION_COMPLIANCE = 'compliance';
    public const SECTION_CONTRACTUAL = 'contractual';

    public const SECTION_LABELS = [
        self::SECTION_GOVERNANCE => 'Tata Kelola',
        self::SECTION_SECURITY => 'Keamanan',
        self::SECTION_DATA_HANDLING => 'Penanganan Data',
        self::SECTION_COMPLIANCE => 'Kepatuhan',
        self::SECTION_CONTRACTUAL => 'Kontraktual',
    ];

    public const ANSWER_YES_NO = 'yes_no';
    public const ANSWER_MULTI_CHOICE = 'multi_choice';
    public const ANSWER_SCALE_1_5 = 'scale_1_5';

    protected $fillable = [
        'org_id',
        'parent_id',
        'library_id', 'library_segment_id',
        'category', 'version', 'question_code', 'section',
        'question_text', 'description', 'regulation_ref',
        'answer_type', 'answer_options', 'weight', 'direction',
        'recommendation_if_no',
        'requires_evidence_upload',
        'is_active', 'sort_order',
    ];

    public function library()
    {
        return $this->belongsTo(QuestionLibrary::class, 'library_id');
    }

    public function librarySegment()
    {
        return $this->belongsTo(QuestionLibrarySegment::class, 'library_segment_id');
    }

    protected $casts = [
        'answer_options' => 'array',
        'weight' => 'integer',
        'direction' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Merge system defaults with tenant overrides + tenant custom questions.
     *
     * Resolution order:
     *   - System default: org_id IS NULL (active, current version)
     *   - Tenant override: org_id = $orgId AND parent_id IS NOT NULL → replaces matching default
     *   - Tenant custom:   org_id = $orgId AND parent_id IS NULL    → appended
     *
     * De-activated overrides remove the default from the effective set.
     */
    public static function effectiveForOrg(?string $orgId): \Illuminate\Support\Collection
    {
        $defaults = self::query()
            ->withoutGlobalScope('org')
            ->whereNull('org_id')
            ->where('is_active', true)
            ->where('version', 'v2_2026')
            ->get()
            ->keyBy('id');

        if ($orgId) {
            $overrides = self::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->whereNotNull('parent_id')
                ->get()
                ->keyBy('parent_id');

            foreach ($overrides as $parentId => $override) {
                if (isset($defaults[$parentId])) {
                    // Replace default with override
                    $defaults[$parentId] = $override;
                }
            }

            $customs = self::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->whereNull('parent_id')
                ->get();

            // Filter out de-activated defaults
            $defaults = $defaults->filter(fn ($q) => $q->is_active);

            return $defaults->values()->merge($customs);
        }

        return $defaults->values();
    }
}
