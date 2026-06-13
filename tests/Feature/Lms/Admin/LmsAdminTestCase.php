<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared base for LMS admin feature tests.
 *
 * Owns the helpers that bootstrap an org/user/role and seed parent rows
 * (course, module). Concrete test classes extend this and only declare
 * helpers unique to their own resource (e.g. video factories for lessons).
 */
abstract class LmsAdminTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an org + a tenant_admin user with the lms.content_admin permission
     * and Sanctum::actingAs them. Returns the user.
     */
    protected function actingAsContentAdmin(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);

        $org = $org ?: Organization::factory()->create();

        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'tenant_admin',
            'is_system'   => true,
            'description' => 'Tenant admin (test)',
            'permissions' => ['lms.content_admin', 'lms.learner'],
        ]);

        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'admin',
            'tenant_role_id' => $role->id,
        ]);

        $this->ensureLmsEntitled($org);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Build an org + a tenant_admin user with the lms.user_admin permission
     * (separate from content_admin per spec D2 — used by 5.4 user viewer).
     */
    protected function actingAsUserAdmin(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);

        $org = $org ?: Organization::factory()->create();

        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'tenant_admin',
            'is_system'   => true,
            'description' => 'Tenant admin (test, user_admin only)',
            'permissions' => ['lms.user_admin', 'lms.learner'],
        ]);

        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'admin',
            'tenant_role_id' => $role->id,
        ]);

        $this->ensureLmsEntitled($org);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Build a user with NO content_admin permission (learner-only).
     */
    protected function actingAsLearner(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);
        $org = $org ?: Organization::factory()->create();

        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'user',
            'is_system'   => true,
            'description' => 'Learner (test)',
            'permissions' => ['lms.learner'],
        ]);

        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'user',
            'tenant_role_id' => $role->id,
        ]);

        $this->ensureLmsEntitled($org);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function ensureLmsEntitled(Organization $org): void
    {
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id'    => null,
            'label'             => 'Learn',
            'href'              => '/learn',
            'icon'              => 'GraduationCap',
            'section'           => 'Menu Utama',
            'sort_order'        => 100,
            'hideable'          => true,
            'required_packages' => [],
        ]);

        TenantModuleEntitlement::firstOrCreate(
            ['org_id' => $org->id, 'menu_id' => $mi->id],
            ['is_entitled' => true],
        );
    }

    protected function makeCourse(?Organization $org = null, string $slug = 'c1', bool $published = true): Course
    {
        return Course::create([
            'org_id'           => $org?->id,
            'slug'             => $slug,
            'title'            => "Course {$slug}",
            'description'      => '',
            'level'            => null,
            'duration_minutes' => 0,
            'thumbnail_url'    => null,
            'published'        => $published,
            'order'            => 1,
            'created_by'       => null,
        ]);
    }

    protected function makeModule(Course $course, string $slug = 'm1', int $order = 1, bool $published = true): Module
    {
        return Module::create([
            'course_id' => $course->id,
            'slug'      => $slug,
            'title'     => "Module {$slug}",
            'order'     => $order,
            'published' => $published,
        ]);
    }
}
