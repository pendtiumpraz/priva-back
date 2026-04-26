<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Pivot DSR ↔ Information System.
 * Setiap DSR bisa affect multiple IS, setiap scope punya per-IS request_types
 * (misal "deletion" untuk Customer DB tapi "withdraw_consent" untuk Marketing CRM).
 */
class DsrRequestScope extends Model
{
    use HasUuids;

    protected $fillable = [
        'dsr_request_id', 'information_system_id', 'shards_affected',
        'request_types', 'sql_pack_status', 'sql_pack_url',
        'sql_pack_generated_at', 'sql_pack_downloaded_at',
    ];

    protected $casts = [
        'shards_affected' => 'array',
        'request_types' => 'array',
        'sql_pack_generated_at' => 'datetime',
        'sql_pack_downloaded_at' => 'datetime',
    ];

    public function dsrRequest()
    {
        return $this->belongsTo(DsrRequest::class, 'dsr_request_id');
    }

    public function informationSystem()
    {
        return $this->belongsTo(InformationSystem::class, 'information_system_id');
    }

    public function executions()
    {
        return $this->hasMany(DsrExecution::class, 'information_system_id', 'information_system_id')
            ->where('dsr_request_id', $this->dsr_request_id);
    }
}
