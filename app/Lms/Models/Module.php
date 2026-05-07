<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $table = 'lms_modules';

    protected $fillable = [
        'course_id', 'slug', 'title', 'description', 'order', 'unlock_after_module_id',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }
}
