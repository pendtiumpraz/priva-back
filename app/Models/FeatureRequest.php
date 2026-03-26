<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'user_id', 'title', 'description',
        'category', 'priority', 'status', 'admin_notes', 'votes',
        'guest_name', 'guest_email',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
