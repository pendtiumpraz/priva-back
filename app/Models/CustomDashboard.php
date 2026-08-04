<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dashboard yang disusun sendiri oleh tenant.
 */
class CustomDashboard extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const MODULES = ['all', 'ropa', 'dpia'];

    protected $fillable = [
        'org_id', 'name', 'description', 'module', 'widgets',
        'owner_user_id', 'visible_roles', 'is_default', 'sequence', 'created_by',
    ];

    protected $casts = [
        'widgets' => 'array',
        'visible_roles' => 'array',
        'is_default' => 'boolean',
        'sequence' => 'integer',
    ];

    /**
     * Dashboard terlihat bila milik pengguna itu sendiri, atau milik
     * organisasi dan perannya termasuk dalam daftar (daftar kosong = semua
     * peran).
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->owner_user_id !== null) {
            return $this->owner_user_id === $user->id;
        }

        $roles = $this->visible_roles ?? [];
        if (empty($roles)) {
            return true;
        }

        return in_array($user->tenant_role_id, $roles, true)
            || in_array($user->role, $roles, true);
    }
}
