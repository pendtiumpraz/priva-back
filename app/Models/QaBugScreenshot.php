<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QaBugScreenshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'bug_report_id', 'file_path', 'file_name',
        'file_size', 'mime_type', 'uploaded_by_name', 'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function bugReport()
    {
        return $this->belongsTo(QaBugReport::class, 'bug_report_id');
    }
}
