<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_quiz_attempts';

    protected $fillable = [
        'user_id', 'org_id', 'quiz_id', 'score', 'passed', 'attempt_number',
        'started_at', 'submitted_at', 'answers',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'score' => 'integer',
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
    ];
}
