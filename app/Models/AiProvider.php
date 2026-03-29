<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    protected $fillable = [
        'slug', 'name', 'api_base_url', 'auth_header', 'auth_prefix',
        'supports_tools', 'supports_streaming', 'is_active', 'sort_order',
        'description', 'website', 'icon',
    ];

    protected $casts = [
        'supports_tools' => 'boolean',
        'supports_streaming' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function models()
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }
}
