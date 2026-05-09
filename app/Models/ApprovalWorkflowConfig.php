<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflowConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'module', 'enabled', 'steps', 'notes', 'updated_by',
    ];

    protected $casts = [
        'steps' => 'array',
        'enabled' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
