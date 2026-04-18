<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MenuItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'parent_menu_id', 'menu_key', 'label', 'href', 'icon', 'section',
        'sort_order', 'hideable', 'required_packages',
    ];

    protected $casts = [
        'hideable' => 'boolean',
        'sort_order' => 'integer',
        'required_packages' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_menu_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_menu_id')->orderBy('sort_order');
    }

    public function whitelists()
    {
        return $this->hasMany(RoleMenuWhitelist::class, 'menu_id');
    }

    public function tenantOverrides()
    {
        return $this->hasMany(TenantMenuOverride::class, 'menu_id');
    }
}
