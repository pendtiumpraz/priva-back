<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiProvider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'api_base_url', 'auth_header', 'auth_prefix',
        'supports_tools', 'supports_streaming', 'is_active', 'sort_order',
        'description', 'website', 'icon',
        // Metadata kepatuhan (UU PDP-aware provider selection)
        'jurisdiction', 'dpa_url', 'privacy_url', 'zdr_available', 'zdr_note',
        'gdpr_status', 'no_training', 'pdp_risk', 'compliance_note',
    ];

    protected $casts = [
        'supports_tools' => 'boolean',
        'supports_streaming' => 'boolean',
        'is_active' => 'boolean',
        'zdr_available' => 'boolean',
        'no_training' => 'boolean',
    ];

    public function models()
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }
}
