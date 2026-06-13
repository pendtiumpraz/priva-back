<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    protected $table = 'lms_quiz_questions';

    protected $fillable = [
        'quiz_id', 'type', 'prompt', 'options', 'correct_answer', 'points', 'order',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'points' => 'integer',
        'order' => 'integer',
    ];

    protected $hidden = ['correct_answer'];
}
