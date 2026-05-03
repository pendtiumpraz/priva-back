<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ropa extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'regulation_code', 'category_id', 'custom_number', 'registration_number',
        'processing_activity', 'entity', 'division',
        'work_unit', 'description', 'kategori_pemrosesan', 'assign_group', 'assignees',
        'risk_level', 'risk_level_locked', 'status', 'purpose', 'legal_basis', 'legal_basis_detail', 'data_categories', 'data_subjects',
        'recipients', 'retention_period', 'retention_due_date', 'security_measures',
        'wizard_data', 'raci_matrix', 'progress',
        'approved_by', 'approved_at', 'submitted_at', 'submitted_by', 'review_notes', 'created_by',
    ];

    protected $casts = [
        'data_categories' => 'array',
        'data_subjects' => 'array',
        'recipients' => 'array',
        'wizard_data' => 'array',
        'raci_matrix' => 'array',
        'assignees' => 'array',
        'risk_level_locked' => 'boolean',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'progress' => 'float',
    ];

    /**
     * Wizard section definitions matching the live PRIVASIMU platform.
     * Each section has required fields that affect progress calculation.
     */
    public const WIZARD_SECTIONS = [
        1 => ['key' => 'detail_pemrosesan', 'label' => 'Detail Pemrosesan', 'fields' => ['processing_activity', 'entity', 'division', 'work_unit', 'description', 'risk_level']],
        2 => ['key' => 'dpo_team', 'label' => 'Data Protection Team/Officer', 'fields' => ['kategori_pemrosesan', 'dpo_name', 'dpo_email', 'dpo_phone']],
        3 => ['key' => 'informasi_pemrosesan', 'label' => 'Informasi Pemrosesan', 'fields' => ['purpose', 'jenis_pemrosesan', 'sistem_terkait', 'legal_basis']],
        4 => ['key' => 'pengumpulan_data', 'label' => 'Pengumpulan Data', 'fields' => ['sumber_data', 'kategori_subjek', 'jenis_data']],
        5 => ['key' => 'penggunaan_penyimpanan', 'label' => 'Penggunaan dan Penyimpanan Data', 'fields' => ['cara_pemrosesan', 'lokasi_penyimpanan']],
        6 => ['key' => 'pengiriman_data', 'label' => 'Pengiriman Data', 'fields' => ['transfer_domestik', 'transfer_internasional', 'negara_tujuan', 'safeguards']],
        7 => ['key' => 'retensi_keamanan', 'label' => 'Retensi dan Keamanan Data', 'fields' => ['retention_period', 'prosedur_pemusnahan', 'langkah_keamanan']],
    ];

    /**
     * Calculate wizard progress (0-100) based on filled sections.
     */
    public function calculateProgress(): float
    {
        $wizardData = $this->wizard_data ?? [];
        $totalFields = 0;
        $filledFields = 0;

        foreach (self::WIZARD_SECTIONS as $section) {
            foreach ($section['fields'] as $field) {
                $totalFields++;
                // Check in wizard_data first, then in direct model fields
                $sectionData = $wizardData[$section['key']] ?? [];
                $value = $sectionData[$field] ?? $this->getAttribute($field);
                if (! empty($value)) {
                    $filledFields++;
                }
            }
        }

        return $totalFields > 0 ? round(($filledFields / $totalFields) * 100, 1) : 0;
    }

    /**
     * Get section completion status.
     */
    public function getSectionStatus(): array
    {
        $wizardData = $this->wizard_data ?? [];
        $statuses = [];

        foreach (self::WIZARD_SECTIONS as $idx => $section) {
            $sectionData = $wizardData[$section['key']] ?? [];
            $filled = 0;
            $total = count($section['fields']);

            foreach ($section['fields'] as $field) {
                $value = $sectionData[$field] ?? $this->getAttribute($field);
                if (! empty($value)) {
                    $filled++;
                }
            }

            $statuses[$idx] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'filled' => $filled,
                'total' => $total,
                'complete' => $filled === $total,
                'status' => $filled === 0 ? 'empty' : ($filled === $total ? 'done' : 'partial'),
            ];
        }

        return $statuses;
    }

    /**
     * Unified DPO list (Sprint E2). Reads wizard_data.dpo_team.dpo_list[]
     * when set; falls back to legacy single {dpo_name, dpo_email, dpo_phone,
     * dpo_jabatan} so existing records still render. Always returns an array
     * of {user_id, name, email, phone, jabatan}.
     */
    public function getDpoListAttribute(): array
    {
        $wiz = $this->wizard_data ?? [];
        $dpoTeam = $wiz['dpo_team'] ?? [];
        $list = $dpoTeam['dpo_list'] ?? null;

        if (is_array($list) && ! empty($list)) {
            return array_values(array_map(fn ($d) => [
                'user_id' => $d['user_id'] ?? null,
                'name' => $d['name'] ?? '',
                'email' => $d['email'] ?? '',
                'phone' => $d['phone'] ?? '',
                'jabatan' => $d['jabatan'] ?? '',
            ], $list));
        }

        // Legacy fallback — single DPO stored in flat fields.
        if (! empty($dpoTeam['dpo_name']) || ! empty($dpoTeam['dpo_email'])) {
            return [[
                'user_id' => null,
                'name' => $dpoTeam['dpo_name'] ?? '',
                'email' => $dpoTeam['dpo_email'] ?? '',
                'phone' => $dpoTeam['dpo_phone'] ?? '',
                'jabatan' => $dpoTeam['dpo_jabatan'] ?? '',
            ]];
        }

        return [];
    }

    /** Same shape as getDpoListAttribute but for PIC rows. */
    public function getPicListAttribute(): array
    {
        $wiz = $this->wizard_data ?? [];
        $dpoTeam = $wiz['dpo_team'] ?? [];
        $list = $dpoTeam['pic_list'] ?? null;

        if (is_array($list) && ! empty($list)) {
            return array_values(array_map(fn ($p) => [
                'user_id' => $p['user_id'] ?? null,
                'name' => $p['name'] ?? '',
                'email' => $p['email'] ?? '',
                'jabatan' => $p['jabatan'] ?? '',
                'divisi' => $p['divisi'] ?? '',
            ], $list));
        }

        if (! empty($dpoTeam['pic_name'])) {
            return [[
                'user_id' => null,
                'name' => $dpoTeam['pic_name'] ?? '',
                'email' => $dpoTeam['pic_email'] ?? '',
                'jabatan' => $dpoTeam['pic_jabatan'] ?? '',
                'divisi' => $dpoTeam['pic_divisi'] ?? '',
            ]];
        }

        return [];
    }

    /**
     * Unified sistem_terkait list — either new array [{system_id, name, lokasi}]
     * or a single legacy string. Always returns an array of objects.
     */
    public function getSistemListAttribute(): array
    {
        $wiz = $this->wizard_data ?? [];
        $info = $wiz['informasi_pemrosesan'] ?? [];
        $raw = $info['sistem_terkait'] ?? null;

        if (is_array($raw) && ! empty($raw)) {
            return array_values(array_map(function ($s) {
                if (is_array($s)) {
                    return [
                        'system_id' => $s['system_id'] ?? null,
                        'name' => $s['name'] ?? '',
                        'lokasi' => $s['lokasi'] ?? '',
                    ];
                }

                // Legacy: array of plain strings.
                return ['system_id' => null, 'name' => (string) $s, 'lokasi' => ''];
            }, $raw));
        }

        if (is_string($raw) && $raw !== '') {
            return [['system_id' => null, 'name' => $raw, 'lokasi' => '']];
        }

        return [];
    }

    /**
     * Retention policy rows resolved with master data lookup.
     * wizard_data.retensi_keamanan.retensi_list[] = [{policy_id, scope_data_type, catatan}]
     * Returns flattened rows with full policy fields joined from
     * retention_policies (so the export blade can render without extra query).
     */
    public function getRetensiRowsAttribute(): array
    {
        $wiz = $this->wizard_data ?? [];
        $ret = $wiz['retensi_keamanan'] ?? [];
        $list = $ret['retensi_list'] ?? null;

        if (! is_array($list) || empty($list)) {
            // Legacy: single masa_retensi string fallback.
            $legacy = $ret['masa_retensi'] ?? $this->retention_period;
            if (! empty($legacy)) {
                return [[
                    'policy_id' => null,
                    'name' => $legacy,
                    'duration_value' => null,
                    'duration_unit' => null,
                    'trigger_event' => $ret['prosedur_pemusnahan'] ?? null,
                    'disposal_method' => null,
                    'scope_data_type' => null,
                    'catatan' => null,
                ]];
            }

            return [];
        }

        $policyIds = array_values(array_filter(array_map(fn ($r) => $r['policy_id'] ?? null, $list)));
        // Guard against a missing retention_policies table: environments where
        // migration 2026_04_22_000006 hasn't run yet must still be able to
        // export RoPA docs. Falls back to an empty policy map so row fields
        // default to the inline values from wizard_data.
        try {
            $policies = $policyIds
                ? RetentionPolicy::whereIn('id', $policyIds)->get()->keyBy('id')
                : collect();
        } catch (\Throwable $e) {
            \Log::warning('RetentionPolicy lookup failed (missing table?): '.$e->getMessage());
            $policies = collect();
        }

        return array_values(array_map(function ($row) use ($policies) {
            $p = $policies->get($row['policy_id'] ?? null);

            return [
                'policy_id' => $row['policy_id'] ?? null,
                'name' => $p?->name ?? ($row['name'] ?? ''),
                'duration_value' => $p?->duration_value,
                'duration_unit' => $p?->duration_unit,
                'trigger_event' => $p?->trigger_event,
                'disposal_method' => $p?->disposal_method,
                'legal_basis' => $p?->legal_basis,
                'scope_data_type' => $row['scope_data_type'] ?? null,
                'catatan' => $row['catatan'] ?? null,
            ];
        }, $list));
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dpias()
    {
        return $this->hasMany(Dpia::class, 'ropa_id');
    }

    /**
     * Many-to-many DPIAs (beyond the legacy single ropa_id parent FK).
     * 1 RoPA bisa di-cover banyak DPIA dari sudut pandang berbeda
     * (e.g. RoPA "Customer Onboarding" → DPIA Privacy + DPIA Security).
     */
    public function dpiaCoverages()
    {
        return $this->belongsToMany(Dpia::class, 'dpia_ropa', 'ropa_id', 'dpia_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    /**
     * Many-to-many: RoPA processing activity bisa pakai banyak Information Systems
     * (1 activity might query customer DB + log DB + analytics warehouse).
     */
    public function informationSystems()
    {
        return $this->belongsToMany(InformationSystem::class, 'information_system_ropa', 'ropa_id', 'information_system_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    /**
     * Many-to-many: RoPA bisa terkait banyak collection point consent
     * (e.g. activity "marketing campaign" link ke cookie banner + email signup form).
     */
    public function consentPoints()
    {
        return $this->belongsToMany(ConsentCollectionPoint::class, 'consent_collection_ropa', 'ropa_id', 'collection_point_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'record_id')
            ->where('module', 'ropa')
            ->orderBy('created_at', 'desc');
    }

    public function category()
    {
        return $this->belongsTo(ProcessingCategory::class, 'category_id');
    }
}
