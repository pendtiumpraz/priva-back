<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class RaciTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'description', 'matrix',
        'is_system', 'is_default', 'usage_count', 'created_by',
    ];

    protected $casts = [
        'matrix' => 'array',
        'is_system' => 'boolean',
        'is_default' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Valid RACI entry shape — used by the controller to validate saves.
     * Role values reference role keys (e.g. "dpo", "it-security"); the UI
     * resolves them to user labels at render time.
     */
    public const ENTRY_SHAPE = [
        'responsible' => 'string',
        'accountable' => 'string',
        'consulted'   => 'array',
        'informed'    => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Count active breaches whose containment_checklist step categories
     * map to categories present in this template. Not perfect "usage" but
     * a safe proxy for the in-use block on destroy.
     */
    public function usageCount(): int
    {
        $matrix = $this->matrix ?? [];
        if (empty($matrix)) return 0;
        // Lightweight: count breaches in same tenant that have any checklist
        // step whose category appears in the matrix.
        $categories = array_keys($matrix);
        if (empty($categories)) return 0;

        $query = BreachIncident::whereNull('deleted_at');
        if ($this->org_id) $query->where('org_id', $this->org_id);
        return $query->get(['id', 'containment_checklist'])
            ->filter(function ($b) use ($categories) {
                $cl = $b->containment_checklist ?? [];
                if (!is_array($cl)) return false;
                foreach ($cl as $step) {
                    $cat = is_array($step) ? ($step['category'] ?? null) : null;
                    if ($cat && in_array($cat, $categories, true)) return true;
                }
                return false;
            })
            ->count();
    }
}
