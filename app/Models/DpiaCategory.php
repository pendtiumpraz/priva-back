<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DpiaCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'name', 'description', 'sequence',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function risks()
    {
        return $this->hasMany(DpiaCategoryRisk::class, 'category_id')
            ->where('is_active', true)
            ->orderBy('sequence');
    }
}
