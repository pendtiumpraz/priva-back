<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    protected $table = 'lms_quizzes';

    protected $fillable = [
        'owner_type', 'owner_key', 'title', 'passing_score', 'time_limit_seconds', 'max_attempts',
    ];

    protected $casts = [
        'passing_score' => 'integer',
        'time_limit_seconds' => 'integer',
        'max_attempts' => 'integer',
    ];

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }
}
