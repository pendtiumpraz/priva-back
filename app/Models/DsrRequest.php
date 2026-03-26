<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class DsrRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'request_id', 'request_type', 'requester_name', 'requester_email',
        'requester_phone', 'description', 'status', 'verification_status', 'response',
        'rejection_reason', 'deadline_at', 'responded_at', 'closed_at',
        'assigned_to', 'created_by',
    ];

    protected $casts = [
        'deadline_at' => 'datetime', 'responded_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
    public function assignee()
    {
        return $this->belongsTo(User::class , 'assigned_to');
    }
}
