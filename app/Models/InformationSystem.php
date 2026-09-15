<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Support\InformationSystemScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InformationSystem extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'code', 'description', 'owner', 'owner_id',
        'source_type', 'connection_type', 'connection_config',
        'is_sharded', 'shards',
        'scanning_status', 'scanning_progress', 'pdp_alert_count', 'pii_alert_count',
        'scan_results', 'ai_scan_results', 'protection_assessments', 'last_scanned_at', 'created_by',
    ];

    protected $casts = [
        'connection_config' => 'array', 'scan_results' => 'array', 'ai_scan_results' => 'array',
        'protection_assessments' => 'array',
        'shards' => 'array',
        'is_sharded' => 'boolean',
        'last_scanned_at' => 'datetime', 'scanning_progress' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Keterlihatan per divisi — diturunkan dari RoPA dan pihak ketiga yang
     * ditautkan lewat pivot, bukan dari kolom sendiri. Aturannya tinggal di
     * InformationSystemScope.
     */
    public function scopeVisibleTo($query, $user)
    {
        InformationSystemScope::terapkan($query, $user, (string) ($user->org_id ?? ''));

        return $query;
    }

    /**
     * Many-to-many: 1 information system bisa muncul di banyak RoPA processing activity.
     * Pivot: information_system_ropa (org_id mirrored for fast tenant scoping).
     */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'information_system_ropa', 'information_system_id', 'ropa_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    /**
     * Pihak ketiga yang memegang/mengoperasikan sistem ini — mis. SaaS tempat
     * datanya benar-benar tersimpan.
     *
     * Sebelum pivot ini ada, pertanyaan "siapa pihak ketiga yang menyentuh data
     * di sistem ini" hanya bisa dijawab lewat RoPA. Sistem yang belum pernah
     * ditautkan ke RoPA — dan itu banyak — tidak menghasilkan jawaban apa pun.
     * `owner_id` tidak bisa dipakai: itu foreign key ke `users`, pemilik
     * internal, bukan pihak ketiga.
     *
     * Perannya memakai Vendor::ROLES yang sama dengan pivot ropa_vendor.
     */
    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'information_system_vendor', 'information_system_id', 'vendor_id')
            ->withPivot('role', 'notes', 'org_id')
            ->withTimestamps();
    }
}
