<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class UserNote extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_notes';

    protected $fillable = ['user_id', 'org_id', 'lesson_id', 'body'];
}
