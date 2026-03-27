<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsentLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'org_id',
        'collection_id',
        'user_identifier',
        'consented_items',
        'policy_version',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'consented_items' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function collectionPoint()
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_id');
    }
}
