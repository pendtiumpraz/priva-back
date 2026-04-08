<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'provider_id', 'model_id', 'name', 'category',
        'context_window', 'max_output_tokens',
        'supports_tools', 'supports_vision', 'is_reasoning',
        'recommended_for_agent',
        'input_price_per_m', 'output_price_per_m',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'supports_tools' => 'boolean',
        'supports_vision' => 'boolean',
        'is_reasoning' => 'boolean',
        'recommended_for_agent' => 'boolean',
        'is_active' => 'boolean',
        'input_price_per_m' => 'decimal:4',
        'output_price_per_m' => 'decimal:4',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
