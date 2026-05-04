<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QaBugReport extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'test_result_id', 'title', 'description', 'severity', 'status',
        'reporter_name', 'reported_at',
        'assigned_to_name', 'resolver_name', 'resolved_at',
        'verified_by_name', 'verified_at', 'resolution_notes',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function screenshots()
    {
        return $this->hasMany(QaBugScreenshot::class, 'bug_report_id');
    }

    public function testResult()
    {
        return $this->belongsTo(QaTestResult::class, 'test_result_id');
    }
}
