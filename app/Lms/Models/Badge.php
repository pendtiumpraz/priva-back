<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    protected $table = 'lms_badges';

    protected $fillable = ['slug', 'name', 'description', 'icon', 'criteria_type', 'criteria_json'];

    protected $casts = [
        'criteria_json' => 'array',
    ];
}
