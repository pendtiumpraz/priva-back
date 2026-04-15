<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsentItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'collection_point_id', 'title', 'description', 'specific_purpose', 'full_text',
        'version', 'is_required', 'is_active',
    ];

    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function collectionPoint()
    {
        return $this->belongsTo(ConsentCollectionPoint::class , 'collection_point_id');
    }
}
