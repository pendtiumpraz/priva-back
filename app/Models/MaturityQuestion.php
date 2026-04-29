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
}
