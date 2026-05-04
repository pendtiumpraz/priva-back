<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QaTestRun extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'version', 'status',
        'started_at', 'closed_at', 'created_by', 'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function results()
    {
        return $this->hasMany(QaTestResult::class, 'test_run_id');
    }
}
