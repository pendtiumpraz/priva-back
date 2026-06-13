<?php

namespace App\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $table = 'lms_videos';

    protected $fillable = ['source', 'external_id', 'playback_policy', 'mux_asset_id', 'duration_seconds', 'uploaded_by'];

    protected $casts = [
        'duration_seconds' => 'integer',
    ];
}
