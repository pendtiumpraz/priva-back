<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DpiaRiskEventTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'category_key', 'category_label', 'sequence', 'risk_event', 'default_description',
        'default_dampak', 'default_probabilitas', 'default_kontrol', 'default_penanganan',
        'is_system', 'org_id', 'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sequence' => 'integer',
        'default_dampak' => 'integer',
        'default_probabilitas' => 'integer',
        'default_kontrol' => 'integer',
    ];
}
