<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscoveryChangelog extends Model
{
    use HasFactory, HasUuids, BelongsToOrg;

    protected $fillable = [
        'org_id',
        'information_system_id',
        'scan_date',
        'total_changes',
        'logs_data',
        'status',
    ];

    protected $casts = [
        'logs_data' => 'array',
        'scan_date' => 'date',
    ];

    public function informationSystem()
    {
        return $this->belongsTo(InformationSystem::class);
    }
}
