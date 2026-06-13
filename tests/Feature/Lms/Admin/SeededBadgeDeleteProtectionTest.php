<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Spec §3.9: "Seeded badges can be edited but not deleted."
 *
 * Per the resolution recorded in the BadgeAdminController docblock, this
 * supersedes D5 ("null-org content read-only for tenant admins") for badges:
 *
 *   - tenant admin CAN edit a null-org (seeded) badge
 *   - tenant admin CANNOT delete a null-org (seeded) badge -> 403
 *   - root CAN delete a null-org badge
 */
class SeededBadgeDeleteProtectionTest extends LmsAdminTestCase
{
    public function test_tenant_admin_cannot_delete_seeded_badge(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $seeded = Badge::create([
            'org_id' => null, 'slug' => 'seeded', 'name' => 'Seeded', 'description' => 'd',
            'icon' => 'i', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'gold', 'params' => []],
        ]);

        $r = $this->deleteJson("/api/lms/admin/badges/{$seeded->id}");
        $r->assertStatus(403);
        $this->assertDatabaseHas('lms_badges', ['id' => $seeded->id, 'deleted_at' => null]);
    }

    public function test_tenant_admin_can_edit_seeded_badge(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $seeded = Badge::create([
            'org_id' => null, 'slug' => 'seeded-edit', 'name' => 'Old Name',
            'description' => 'd', 'icon' => 'i', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'gold', 'params' => []],
        ]);

        $r = $this->putJson("/api/lms/admin/badges/{$seeded->id}", [
            'name' => 'New Name',
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('lms_badges', ['id' => $seeded->id, 'name' => 'New Name']);
    }

    public function test_root_can_delete_seeded_badge(): void
    {
        $org = Organization::factory()->create();
        $seeded = Badge::create([
            'org_id' => null, 'slug' => 'root-deletes', 'name' => 'Root', 'description' => 'd',
            'icon' => 'i', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'gold', 'params' => []],
        ]);

        // Bootstrap a root user (User::role = 'root') with content_admin
        // permission and entitlements present.
        config(['lms.enabled' => true]);
        $this->ensureLmsEntitled($org);
        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'tenant_admin',
            'is_system'   => true,
            'description' => 'Root in test',
            'permissions' => ['lms.content_admin', 'lms.learner'],
        ]);
        $rootUser = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'root',
            'tenant_role_id' => $role->id,
        ]);
        Sanctum::actingAs($rootUser);

        $r = $this->deleteJson("/api/lms/admin/badges/{$seeded->id}");
        $r->assertNoContent();
        $this->assertSoftDeleted('lms_badges', ['id' => $seeded->id]);
    }
}
