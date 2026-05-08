<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_badges';

    protected $fillable = ['user_id', 'org_id', 'badge_id', 'awarded_at'];

    protected $casts = [
        'awarded_at' => 'datetime',
    ];

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }
}
