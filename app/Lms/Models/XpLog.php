<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class XpLog extends Model
{
    use BelongsToOrg;

    public $timestamps = false;

    protected $table = 'lms_xp_log';

    protected $fillable = ['user_id', 'org_id', 'action', 'xp_amount', 'ref_type', 'ref_id', 'created_at'];

    protected $casts = [
        'xp_amount' => 'integer',
        'created_at' => 'datetime',
    ];
}
