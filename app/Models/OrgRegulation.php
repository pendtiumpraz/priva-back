<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Status enable/disable regulasi add-on per tenant (pivot).
 *
 * Sengaja BUKAN model ber-BelongsToOrg: selalu difilter org_id eksplisit oleh
 * RegulationService supaya aman dipanggil di konteks CLI/superadmin.
 */
class OrgRegulation extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'regulation_code', 'enabled', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
