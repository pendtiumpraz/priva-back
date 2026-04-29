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

    public const ALL_CATEGORIES = [
        self::CATEGORY_CLOUD,
        self::CATEGORY_SAAS,
        self::CATEGORY_DATA_PROCESSOR,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_CLOUD => 'Cloud Infrastructure / IaaS',
        self::CATEGORY_SAAS => 'SaaS Application',
        self::CATEGORY_DATA_PROCESSOR => 'Data Processor (manual handling)',
    ];

    public const CATEGORY_DESCRIPTIONS = [
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
        'category', 'version', 'question_code', 'section',
        'question_text', 'description', 'regulation_ref',
        'answer_type', 'answer_options', 'weight', 'direction',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'answer_options' => 'array',
        'weight' => 'integer',
        'direction' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
