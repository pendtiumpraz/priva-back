<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'created_by', 'name', 'target_module',
        'total_files', 'completed_files', 'failed_files', 'status',
    ];

    protected $casts = [
        'total_files' => 'integer',
        'completed_files' => 'integer',
        'failed_files' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function imports()
    {
        return $this->hasMany(DocumentImport::class, 'batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Recalculate batch counters from child imports.
     */
    public function recalculate(): void
    {
        $this->completed_files = $this->imports()->where('status', 'completed')->count();
        $this->failed_files = $this->imports()->where('status', 'failed')->count();

        $total = $this->total_files;
        $done = $this->completed_files + $this->failed_files;

        if ($done >= $total) {
            $this->status = $this->failed_files > 0
                ? ($this->completed_files > 0 ? 'partial_failure' : 'failed')
                : 'completed';
        }

        $this->save();
    }
}
