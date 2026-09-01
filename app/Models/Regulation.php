<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry regulasi platform (landlord — tidak org-scoped).
 *
 * Kode core (selalu aktif, tak bisa dimatikan) di {@see CORE_CODES}.
 */
class Regulation extends Model
{
    use HasUuids;

    public const CORE_CODES = ['uu_pdp', 'pp_33'];

    protected $fillable = [
        'code', 'name', 'short', 'category', 'is_core', 'default_enabled',
        'description', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'default_enabled' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public static function isCore(string $code): bool
    {
        return in_array($code, self::CORE_CODES, true);
    }
}
