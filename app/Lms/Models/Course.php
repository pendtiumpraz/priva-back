<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $table = 'lms_courses';

    protected $fillable = [
        'org_id', 'slug', 'title', 'description', 'level', 'duration_minutes',
        'regulation_code', 'thumbnail_url', 'published', 'order', 'created_by',
    ];

    protected $casts = [
        'published' => 'boolean',
        'order' => 'integer',
        'duration_minutes' => 'integer',
    ];

    public function modules()
    {
        return $this->hasMany(Module::class)->orderBy('order');
    }
}
