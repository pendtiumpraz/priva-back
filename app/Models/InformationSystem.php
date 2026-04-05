<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class InformationSystem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'owner', 'owner_id', 'source_type', 'connection_config',
        'scanning_status', 'scanning_progress', 'pdp_alert_count', 'pii_alert_count',
        'scan_results', 'ai_scan_results', 'last_scanned_at', 'created_by',
    ];

    protected $casts = [
        'connection_config' => 'array', 'scan_results' => 'array', 'ai_scan_results' => 'array',
        'last_scanned_at' => 'datetime', 'scanning_progress' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
}
