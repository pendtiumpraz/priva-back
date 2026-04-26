<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class InformationSystem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'code', 'description', 'owner', 'owner_id',
        'source_type', 'connection_type', 'connection_config',
        'is_sharded', 'shards',
        'scanning_status', 'scanning_progress', 'pdp_alert_count', 'pii_alert_count',
        'scan_results', 'ai_scan_results', 'protection_assessments', 'last_scanned_at', 'created_by',
    ];

    protected $casts = [
        'connection_config' => 'array', 'scan_results' => 'array', 'ai_scan_results' => 'array',
        'protection_assessments' => 'array',
        'shards' => 'array',
        'is_sharded' => 'boolean',
        'last_scanned_at' => 'datetime', 'scanning_progress' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
