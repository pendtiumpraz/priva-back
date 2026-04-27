<?php

namespace App\Models\Landing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead capture dari landing — Contact Us + Request Demo + Partnership.
 * Privasimu TIDAK publish harga (banyak klien gov), jadi semua jalur konversi
 * berakhir di sini, bukan ke checkout.
 */
class LandingLead extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'employee_count' => 'integer',
        'handled_at' => 'datetime',
    ];

    public const INTENTS = ['contact', 'demo', 'partnership', 'other'];

    public const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'rejected'];
}
