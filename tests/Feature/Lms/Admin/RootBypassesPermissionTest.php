<?php

namespace Tests\Feature\Lms\Admin;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Regression coverage for the platform-role bypass on /api/lms/admin/*.
 *
 * Platform roles (`root`, `superadmin`) have no tenant assignment — no
 * `org_id`, no `tenant_role_id`, no entitlement row. They must still reach
 * the admin endpoints; the per-tenant gates (`lms.entitled` and
 * `permission:`) short-circuit for them.
 *
 * Tenant users keep working unchanged:
 *  - with the right `permission:` → 200
 *  - without the right `permission:` → 403
 */
class RootBypassesPermissionTest extends LmsAdminTestCase
{
    public function test_root_without_tenant_can_list_courses(): void
    {
        config(['lms.enabled' => true]);

        $user = User::factory()->create([
            'org_id'         => null,
            'tenant_role_id' => null,
            'role'           => 'root',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/lms/admin/courses')->assertOk();
    }

    public function test_superadmin_without_tenant_can_list_courses(): void
    {
        config(['lms.enabled' => true]);

        $user = User::factory()->create([
            'org_id'         => null,
            'tenant_role_id' => null,
            'role'           => 'superadmin',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/lms/admin/courses')->assertOk();
    }

    public function test_tenant_user_without_content_admin_permission_gets_403(): void
    {
        // Regression check — the platform bypass must NOT leak to ordinary users.
        $this->actingAsLearner();

        $this->getJson('/api/lms/admin/courses')->assertStatus(403);
    }

    public function test_tenant_user_with_content_admin_permission_still_works(): void
    {
        // Regression check — existing tenant path is unchanged.
        $this->actingAsContentAdmin();

        $this->getJson('/api/lms/admin/courses')->assertOk();
    }
}
