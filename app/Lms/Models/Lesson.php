<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $table = 'lms_lessons';

    protected $fillable = [
        'module_id', 'slug', 'title', 'body', 'order', 'duration_seconds', 'video_id',
        'steps', 'tips', 'tags',
    ];

    protected $casts = [
        'order' => 'integer',
        'duration_seconds' => 'integer',
        'steps' => 'array',
        'tips' => 'array',
        'tags' => 'array',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }
}
