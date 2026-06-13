<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNote extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_user_notes';

    protected $fillable = ['user_id', 'org_id', 'lesson_id', 'body'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
