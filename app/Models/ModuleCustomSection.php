<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Custom wizard section (Phase 0 — see CUSTOM_WIZARD_PLAN.md §4.1).
 *
 * Tenant-scoped via BelongsToOrg. Pairs with ModuleCustomField — a section
 * groups N custom fields under a labelled header that the wizard renders
 * inline after / interleaved with the built-in sections.
 *
 * Built-in sections live in code (sort_order 0–99) and are not stored here.
 * Org-custom rows start at sort_order=100 by convention.
 */
class ModuleCustomSection extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'module', 'origin', 'section_key', 'section_label',
        'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Custom fields registered under this section.
     *
     * Note: ModuleCustomField doesn't have a section_id FK — the relationship
     * is composite via (org_id, module, section_key). This relation matches
     * the same triple, so eager loading works the same way as a normal
     * hasMany.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(ModuleCustomField::class, 'section_key', 'section_key')
            ->where('module_custom_fields.org_id', $this->org_id)
            ->where('module_custom_fields.module', $this->module);
    }

    public function scopeForOrg(Builder $q, string $orgId): Builder
    {
        return $q->where('org_id', $orgId);
    }

    public function scopeForModule(Builder $q, string $module): Builder
    {
        return $q->where('module', $module);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
