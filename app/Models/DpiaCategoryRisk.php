<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DpiaCategoryRisk extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'category_id', 'risk_event', 'description', 'sequence',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(DpiaCategory::class, 'category_id');
    }
}
