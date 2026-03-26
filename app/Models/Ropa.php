<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ropa extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'registration_number', 'processing_activity', 'entity', 'division',
        'work_unit', 'description', 'kategori_pemrosesan', 'assign_group',
        'risk_level', 'status', 'purpose', 'legal_basis', 'data_categories', 'data_subjects',
        'recipients', 'retention_period', 'retention_due_date', 'security_measures',
        'wizard_data', 'progress',
        'approved_by', 'approved_at', 'created_by',
    ];

    protected $casts = [
        'data_categories' => 'array',
        'data_subjects' => 'array',
        'recipients' => 'array',
        'wizard_data' => 'array',
        'approved_at' => 'datetime',
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
                if (!empty($value)) {
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
                if (!empty($value)) $filled++;
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

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'record_id')
            ->where('module', 'ropa')
            ->orderBy('created_at', 'desc');
    }
}
