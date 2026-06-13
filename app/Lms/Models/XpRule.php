<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class XpRule extends Model
{
    protected $table = 'lms_xp_rules';

    protected $fillable = ['action_key', 'xp_amount', 'conditions'];

    protected $casts = [
        'xp_amount' => 'integer',
        'conditions' => 'array',
    ];
}
