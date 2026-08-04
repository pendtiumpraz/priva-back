<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu kontrol/pengamanan yang dapat dipakai ulang lintas DPIA.
 */
class ControlLibraryItem extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const CATEGORIES = ['teknis', 'organisasi', 'hukum', 'fisik'];

    public const TYPES = ['preventif', 'detektif', 'korektif'];

    protected $fillable = [
        'org_id', 'code', 'category', 'title', 'description', 'control_type',
        'implementation_guidance', 'reference', 'default_effectiveness',
        'sequence', 'is_active', 'is_seeded', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_seeded' => 'boolean',
        'default_effectiveness' => 'integer',
        'sequence' => 'integer',
    ];
}
