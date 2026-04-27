<?php

namespace App\Models\Landing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandingTestimonial extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'rating' => 'integer',
        'order_index' => 'integer',
    ];
}
