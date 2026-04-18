<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TenantMenuOverride extends Model
{
    use HasUuids;

    protected $table = 'tenant_menu_override';

    protected $fillable = ['org_id', 'menu_id', 'role', 'is_visible'];

    protected $casts = ['is_visible' => 'boolean'];

    public function menu()
    {
        return $this->belongsTo(MenuItem::class, 'menu_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
