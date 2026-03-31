<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SystemLogAnalysis extends Model
{
    use HasUuids;

    protected $fillable = [
        'raw_log_snippet',
        'ai_analysis',
        'status',
        'error_message',
        'created_by'
    ];

    protected $casts = [
        'ai_analysis' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
