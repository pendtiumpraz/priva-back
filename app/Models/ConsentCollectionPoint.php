<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class ConsentCollectionPoint extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'collection_id', 'name', 'domain', 'redirect_url',
        'settings', 'webhook_url', 'created_by',
    ];

    protected $casts = ['settings' => 'array'];

    protected static function booted(): void
    {
        // Any write to a collection invalidates the cached public config so
        // tenant-site embeds see fresh banner settings on their next reload.
        // We bust both key variants (code lookup + UUID lookup).
        $bust = function (self $c) {
            Cache::forget('consent:config:' . sha1($c->collection_id));
            Cache::forget('consent:config:' . sha1($c->id));
            Cache::forget('consent:collection:' . sha1($c->collection_id));
            Cache::forget('consent:collection:' . sha1($c->id));
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public function bustConsentCache(): void
    {
        Cache::forget('consent:config:' . sha1($this->collection_id));
        Cache::forget('consent:config:' . sha1($this->id));
        Cache::forget('consent:collection:' . sha1($this->collection_id));
        Cache::forget('consent:collection:' . sha1($this->id));
    }

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
