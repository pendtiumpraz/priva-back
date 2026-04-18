<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TenantModuleEntitlement extends Model
{
    use HasUuids;

    protected $table = 'tenant_module_entitlements';

    protected $fillable = [
        'org_id', 'menu_id', 'is_entitled', 'valid_until', 'notes',
    ];

    protected $casts = [
        'is_entitled' => 'boolean',
        'valid_until' => 'date',
    ];

    public function menu()
    {
        return $this->belongsTo(MenuItem::class, 'menu_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isActive(): bool
    {
        if (!$this->is_entitled) return false;
        if ($this->valid_until && $this->valid_until->isPast()) return false;
        return true;
    }
}
