<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Baris tautan DPIA ↔ pihak ketiga, lengkap dengan perannya.
 *
 * Sengaja memakai kosakata `role` yang sama dengan `ropa_vendor`: peran menurut
 * UU PDP tidak berubah hanya karena dilihat dari sisi penilaian risiko, dan dua
 * kosakata untuk satu hal adalah cara tercepat membuat laporan gabungan salah.
 */
class DpiaVendor extends Pivot
{
    protected $table = 'dpia_vendor';

    public $incrementing = false;
}
