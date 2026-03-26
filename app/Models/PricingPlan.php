<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PricingPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'package_type', 'price_perpetual', 'price_monthly',
        'price_yearly', 'features', 'is_popular', 'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_popular' => 'boolean',
        'price_perpetual' => 'decimal:0',
        'price_monthly' => 'decimal:0',
        'price_yearly' => 'decimal:0',
    ];
}
