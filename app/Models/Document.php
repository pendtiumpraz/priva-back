<?php
namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Generic stored file. Bytes live on tenant disk via TenantStorageService;
 * this row is the metadata index. kind namespaces the use-case (dsr.*, vendor.*).
 */
class Document extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'kind',
        'source_type', 'source_id',
        'name', 'mime_type', 'size_bytes',
        'storage_path', 'storage_driver',
        'uploaded_by', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
