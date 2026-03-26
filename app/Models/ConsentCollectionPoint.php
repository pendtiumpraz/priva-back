<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsentCollectionPoint extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'collection_id', 'name', 'domain', 'redirect_url',
        'settings', 'created_by',
    ];

    protected $casts = ['settings' => 'array'];

    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }
    public function items()
    {
        return $this->hasMany(ConsentItem::class , 'collection_point_id');
    }
    public function records()
    {
        return $this->hasMany(ConsentRecord::class , 'collection_point_id');
    }
}
