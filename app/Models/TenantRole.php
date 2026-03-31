<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantRole extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_role_id');
    }
}
