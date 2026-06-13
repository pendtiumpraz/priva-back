<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class UserModuleProgress extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_module_progress';

    protected $fillable = [
        'user_id', 'org_id', 'module_id', 'status', 'started_at', 'completed_at', 'score',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'score' => 'integer',
    ];
}
