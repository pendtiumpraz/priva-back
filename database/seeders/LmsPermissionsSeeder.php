<?php

namespace Database\Seeders;

use App\Models\TenantRole;
use Illuminate\Database\Seeder;

class LmsPermissionsSeeder extends Seeder
{
    public const KEYS = [
        'lms.learner',
        'lms.content_admin',
        'lms.user_admin',
        'lms.certificate_admin',
    ];

    public const SYSTEM_DEFAULTS = [
        'tenant_admin' => ['lms.learner', 'lms.content_admin', 'lms.user_admin', 'lms.certificate_admin'],
        'superadmin'   => ['lms.learner', 'lms.content_admin', 'lms.user_admin', 'lms.certificate_admin'],
        'user'         => ['lms.learner'],
    ];

    public function run(): void
    {
        foreach (self::SYSTEM_DEFAULTS as $roleName => $newKeys) {
            $roles = TenantRole::query()
                ->where('is_system', true)
                ->where('name', $roleName)
                ->get();

            foreach ($roles as $role) {
                $existing = is_array($role->permissions) ? $role->permissions : [];
                $merged = array_values(array_unique(array_merge($existing, $newKeys)));
                if ($merged !== $existing) {
                    $role->permissions = $merged;
                    $role->save();
                }
            }
        }
    }
}
