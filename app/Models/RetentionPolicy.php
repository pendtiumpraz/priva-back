<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class RetentionPolicy extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'description',
        'duration_value', 'duration_unit',
        'trigger_event', 'disposal_method',
        'legal_basis', 'created_by',
    ];

    protected $casts = [
        'duration_value' => 'integer',
    ];

    public const UNITS = ['day', 'month', 'year', 'indefinite'];
    public const DISPOSAL_METHODS = ['delete', 'anonymize', 'archive'];

    /**
     * Human-readable label — "5 tahun, hapus setelah Karyawan resign".
     */
    public function getDisplayLabelAttribute(): string
    {
        $duration = $this->duration_unit === 'indefinite'
            ? 'Tidak terbatas'
            : (($this->duration_value ?? 0) . ' ' . $this->unitLabel());
        $disposal = match ($this->disposal_method) {
            'delete' => 'dihapus',
            'anonymize' => 'dianonimisasi',
            'archive' => 'diarsipkan',
            default => $this->disposal_method,
        };
        $trigger = $this->trigger_event ? " setelah {$this->trigger_event}" : '';
        return "{$this->name} — {$duration}, {$disposal}{$trigger}";
    }

    private function unitLabel(): string
    {
        return match ($this->duration_unit) {
            'day' => 'hari',
            'month' => 'bulan',
            'year' => 'tahun',
            default => $this->duration_unit,
        };
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Count active ROPAs that reference this policy via
     * wizard_data.retensi_keamanan.retensi_list[].policy_id
     * OR legacy wizard_data.retensi_keamanan.policy_id single field.
     */
    public function usageCount(): int
    {
        return Ropa::where('org_id', $this->org_id)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereJsonContains('wizard_data->retensi_keamanan->retensi_list', ['policy_id' => $this->id])
                  ->orWhere('wizard_data->retensi_keamanan->policy_id', $this->id);
            })
            ->count();
    }
}
