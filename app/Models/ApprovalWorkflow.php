<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflow extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids, BelongsToOrg;

    protected $fillable = [
        'org_id', 'module', 'record_id', 'steps', 'current_step', 'status', 'rejection_reason'
    ];

    protected $casts = [
        'steps' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
