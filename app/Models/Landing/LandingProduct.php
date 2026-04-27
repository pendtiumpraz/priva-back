<?php

namespace App\Models\Landing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandingProduct extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'features' => 'array',
        'faqs' => 'array',
        'is_published' => 'boolean',
        'order_index' => 'integer',
    ];
}
