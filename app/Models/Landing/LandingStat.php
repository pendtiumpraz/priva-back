<?php

namespace App\Models\Landing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandingStat extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'is_published' => 'boolean',
        'order_index' => 'integer',
    ];
}
