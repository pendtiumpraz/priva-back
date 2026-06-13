<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $table = 'lms_modules';

    protected $fillable = [
        'course_id', 'slug', 'title', 'description', 'order', 'unlock_after_module_id', 'published',
    ];

    protected $casts = [
        'order' => 'integer',
        'published' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    /**
     * Scope a query to only include published modules.
     * Used on learner-side reads to suppress drafts (admin endpoints
     * deliberately do NOT apply this scope).
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }
}
