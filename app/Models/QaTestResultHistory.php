<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QaTestResultHistory extends Model
{
    use HasUuids;

    protected $table = 'qa_test_result_history';

    public $timestamps = false;

    protected $fillable = [
        'test_result_id', 'previous_status', 'status',
        'tester_name', 'notes', 'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function testResult()
    {
        return $this->belongsTo(QaTestResult::class, 'test_result_id');
    }
}
