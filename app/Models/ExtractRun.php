<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtractRun extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    public const TARGETS = ['csv', 'hubspot', 'salesforce', 'mailchimp', 'webhook'];

    protected $fillable = [
        'org_id', 'initiated_by_user_id', 'source', 'filters',
        'output_target', 'output_target_ref',
        'record_count', 'success_count', 'failure_count',
        'status', 'error_summary', 'result_meta',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'result_meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
