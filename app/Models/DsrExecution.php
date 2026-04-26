<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DSR Execution log — per-shard per-request_type evidence.
 * Privasimu TIDAK PERNAH execute SQL — admin klien execute, lalu upload bukti.
 */
class DsrExecution extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'dsr_request_id', 'information_system_id', 'shard_name',
        'request_type', 'sql_executed', 'rows_affected', 'status',
        'executed_at', 'executed_by_email', 'evidence_file_id',
        'notes', 'failure_reason',
    ];

    protected $casts = [
        'rows_affected' => 'integer',
        'executed_at' => 'datetime',
    ];

    public function dsrRequest()
    {
        return $this->belongsTo(DsrRequest::class, 'dsr_request_id');
    }

    public function informationSystem()
    {
        return $this->belongsTo(InformationSystem::class, 'information_system_id');
    }

    public function evidenceFile()
    {
        return $this->belongsTo(\App\Models\Document::class, 'evidence_file_id');
    }

    /**
     * Cek apakah execution sudah final (executed/skipped/failed).
     * Pending = belum ada bukti dari admin klien.
     */
    public function isFinal(): bool
    {
        return in_array($this->status, ['executed', 'skipped', 'failed'], true);
    }

    /**
     * Cek apakah counts terhadap completion (executed atau skipped, BUKAN failed).
     */
    public function countsAsComplete(): bool
    {
        return in_array($this->status, ['executed', 'skipped'], true);
    }
}
