<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Baris tautan RoPA ↔ pihak ketiga, lengkap dengan perannya.
 *
 * Pivot class dipakai supaya `data_shared` terbaca sebagai array saat dibaca
 * lewat relasi. Penulisan tetap lewat sync() (query builder, tanpa cast), jadi
 * nilai JSON di-encode di ModuleCrudController::syncRopaVendors().
 */
class RopaVendor extends Pivot
{
    protected $table = 'ropa_vendor';

    public $incrementing = false;

    protected $casts = [
        'data_shared' => 'array',
    ];
}
