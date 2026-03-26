<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AiResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'user_id', 'feature_type', 'record_id', 'record_type',
        'input_data', 'result_data', 'tokens_used', 'cost_estimate',
    ];

    protected $casts = [
        'input_data' => 'array',
        'result_data' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
