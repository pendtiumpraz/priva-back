<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 1 — Library wrapper untuk question bank.
 *
 * 1 library = 1 set pertanyaan utuh untuk satu use case (mis. "PDP UU
 * 27/2022", "ISO 27001 Lite", "Custom — Vendor Cloud").
 *
 * Akses:
 *   - org_id NULL + is_locked=true → template platform global, tenant
 *     hanya bisa lihat + clone (tidak bisa edit langsung)
 *   - org_id = tenant              → library privat milik tenant tsb
 *
 * Pinned ke landlord karena library shared cross-tenant (template) DAN
 * library tenant-scoped sama-sama ada di tabel ini. Tenant filtering
 * dilakukan eksplisit di controller/scope.
 */
class QuestionLibrary extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    public const SOURCE_SEEDED = 'seeded';

    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_CLONED = 'cloned';

    /**
     * Fork copy-on-write dari template platform: dibuat OTOMATIS saat org
     * pertama kali mengedit template platform via Bank Pertanyaan. Fork
     * men-shadow template asalnya (cloned_from_library_id) di list +
     * picker — org melihat SATU entri per template: fork-nya kalau ada,
     * else template platform asli. "Reset ke Default" = soft-delete fork.
     */
    public const SOURCE_FORKED = 'forked';

    protected $fillable = [
        'org_id',
        'name',
        'slug',
        'description',
        'category',
        'version',
        'source',
        'is_active',
        'is_locked',
        'segments_count',
        'questions_count',
        'tags',
        'created_by',
        'cloned_from_library_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'segments_count' => 'integer',
        'questions_count' => 'integer',
        'tags' => 'array',
    ];

    public function segments()
    {
        return $this->hasMany(QuestionLibrarySegment::class, 'library_id')
            ->orderBy('order_index');
    }

    public function questions()
    {
        return $this->hasMany(VendorQuestionnaire::class, 'library_id');
    }

    /**
     * Library yang visible untuk org tertentu: template global (org_id NULL)
     * + library milik org sendiri. Selalu filter `is_active=true` di caller
     * untuk list page; admin builder boleh include inactive.
     */
    public function scopeVisibleTo($query, ?string $orgId)
    {
        return $query->where(function ($q) use ($orgId) {
            $q->whereNull('org_id');
            if ($orgId) {
                $q->orWhere('org_id', $orgId);
            }
        });
    }

    /**
     * Recompute counter cache. Dipanggil dari controller setelah bulk
     * add/remove pertanyaan supaya list page cepat tanpa COUNT subquery.
     */
    public function refreshCounters(): void
    {
        $this->segments_count = $this->segments()->count();
        $this->questions_count = $this->questions()->count();
        $this->save();
    }
}
