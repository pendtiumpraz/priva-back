<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lapisan manual peta alur data satu RoPA.
 *
 * Baris ini hanya ada bila pengguna pernah menyunting petanya. RoPA tanpa
 * baris di sini tetap punya peta — yang otomatis, diturunkan saat diminta.
 */
class RopaDataFlow extends Model
{
    use BelongsToOrg, HasUuids;

    protected $fillable = [
        'org_id', 'ropa_id', 'manual_nodes', 'manual_edges',
        'overrides', 'hidden_keys', 'positions', 'notes', 'updated_by',
    ];

    protected $casts = [
        'manual_nodes' => 'array',
        'manual_edges' => 'array',
        'overrides' => 'array',
        'hidden_keys' => 'array',
        'positions' => 'array',
    ];

    public function ropa(): BelongsTo
    {
        return $this->belongsTo(Ropa::class);
    }
}
