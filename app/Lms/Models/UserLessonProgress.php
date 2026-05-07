<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class UserLessonProgress extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_lesson_progress';

    protected $fillable = ['user_id', 'org_id', 'lesson_id', 'completed_at', 'watched_seconds'];

    protected $casts = [
        'completed_at' => 'datetime',
        'watched_seconds' => 'integer',
    ];
}
