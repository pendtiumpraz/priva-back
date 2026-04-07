<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentImport extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'uploaded_by', 'original_filename', 'storage_path',
        'file_type', 'file_size', 'target_module', 'status', 'progress',
        'status_message', 'extracted_data', 'mapped_fields', 'confidence_scores',
        'created_record_id', 'batch_id', 'error_message', 'retry_count',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'mapped_fields' => 'array',
        'confidence_scores' => 'array',
        'file_size' => 'integer',
        'progress' => 'integer',
        'retry_count' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function batch()
    {
        return $this->belongsTo(DocumentImportBatch::class, 'batch_id');
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
