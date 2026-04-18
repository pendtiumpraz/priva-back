<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MenuItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'menu_key', 'label', 'href', 'icon', 'section', 'sort_order', 'hideable',
    ];

    protected $casts = [
        'hideable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function whitelists()
    {
        return $this->hasMany(RoleMenuWhitelist::class, 'menu_id');
    }

    public function tenantOverrides()
    {
        return $this->hasMany(TenantMenuOverride::class, 'menu_id');
    }
}
