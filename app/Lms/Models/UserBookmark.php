<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class UserBookmark extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_bookmarks';

    protected $fillable = ['user_id', 'org_id', 'lesson_id'];
}
