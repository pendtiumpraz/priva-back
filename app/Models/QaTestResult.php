<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QaTestResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'test_run_id', 'test_case_id', 'role', 'status',
        'tester_name', 'tested_at', 'notes',
    ];

    protected $casts = [
        'tested_at' => 'datetime',
    ];

    public function testCase()
    {
        return $this->belongsTo(QaTestCase::class, 'test_case_id');
    }

    public function testRun()
    {
        return $this->belongsTo(QaTestRun::class, 'test_run_id');
    }

    public function bugs()
    {
        return $this->hasMany(QaBugReport::class, 'test_result_id');
    }
}
