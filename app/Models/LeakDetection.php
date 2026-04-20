<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LeakDetection extends Model
{
    use HasUuids;

    protected $fillable = [
        'system_id', 'org_id', 'user_id',
        'table_name', 'match_mode', 'columns', 'query_template',
        'found_count', 'leak_confirmed', 'sample_masked',
    ];

    protected $casts = [
        'columns' => 'array',
        'query_template' => 'array',
        'sample_masked' => 'array',
        'leak_confirmed' => 'boolean',
    ];
}
