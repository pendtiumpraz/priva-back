<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Badge extends Model
{
    use SoftDeletes;

    protected $table = 'lms_badges';

    protected $fillable = [
        'org_id',
        'slug',
        'name',
        'description',
        'icon',
        'criteria_type',
        'criteria_json',
    ];

    protected $casts = [
        'criteria_json' => 'array',
    ];

    public function userBadges()
    {
        return $this->hasMany(UserBadge::class);
    }
}
