<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class OrgLeaderboard extends Model
{
    public $timestamps = false;

    protected $table = 'lms_org_leaderboard';

    protected $fillable = [
        'org_id', 'user_id', 'xp_total', 'badges_count', 'courses_completed', 'computed_at',
    ];

    protected $casts = [
        'xp_total' => 'integer',
        'badges_count' => 'integer',
        'courses_completed' => 'integer',
        'computed_at' => 'datetime',
    ];
}
