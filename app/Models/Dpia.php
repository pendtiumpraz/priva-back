<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dpia extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'regulation_code', 'registration_number', 'ropa_id', 'risk_level', 'status',
        'description', 'risk_assessment', 'mitigation_measures',
        'wizard_data', 'raci_matrix', 'progress',
        'approver_id', 'approved_at', 'created_by',
        'assigned_roles',
    ];

    protected $casts = [
        'risk_assessment' => 'array',
        'mitigation_measures' => 'array',
        'wizard_data' => 'array',
        'raci_matrix' => 'array',
        'assigned_roles' => 'array',
        'approved_at' => 'datetime',
        'progress' => 'float',
    ];

    /**
     * DPIA Wizard: 3 sections matching live platform
     */
    public const WIZARD_SECTIONS = [
        1 => ['key' => 'informasi_dpia', 'label' => 'Informasi DPIA'],
        2 => ['key' => 'koneksi_ropa', 'label' => 'Koneksi ROPA'],
        3 => ['key' => 'potensi_risiko', 'label' => 'Potensi Risiko'],
    ];

    /**
     * 21 risk assessment categories matching live platform
     */
    public const RISK_CATEGORIES = [
        'Dasar Hukum Pemrosesan',
        'Pemrosesan Data Pribadi yang Sah',
        'Kesesuaian Tujuan Pemrosesan',
        'Minimisasi Data',
        'Keakuratan Data',
        'Pembatasan Penyimpanan',
        'Integritas dan Kerahasiaan',
        'Akuntabilitas',
        'Hak Subjek Data - Akses',
        'Hak Subjek Data - Koreksi',
        'Hak Subjek Data - Hapus',
        'Hak Subjek Data - Portabilitas',
        'Persetujuan dan Consent',
        'Transfer Data Lintas Batas',
        'Enkripsi dan Pseudonymization',
        'Kontrol Akses',
        'Monitoring dan Logging',
        'Retensi Data',
        'Manajemen Insiden',
        'Pelatihan dan Kesadaran',
        'Penilaian Dampak Berkala',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'record_id')
            ->where('module', 'dpia')
            ->orderBy('created_at', 'desc');
    }
}
