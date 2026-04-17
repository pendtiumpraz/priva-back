<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ModuleComment extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'org_id', 'module', 'record_id', 'user_id', 'parent_id', 'comment',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->with(['user', 'children'])->orderBy('created_at');
    }

    public function scopeForRecord($query, string $module, string $recordId)
    {
        return $query->where('module', $module)->where('record_id', $recordId);
    }
}
