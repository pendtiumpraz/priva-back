<?php

namespace Tests\Feature\Lms;

use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureLmsEntitledMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['auth:sanctum', 'lms.entitled'])
            ->get('/_test/lms-gate', fn () => response()->json(['ok' => true]));
    }

    public function test_returns_503_when_lms_globally_disabled(): void
    {
        config(['lms.enabled' => false]);
        $user = User::factory()->create(['org_id' => Organization::factory()->create()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/_test/lms-gate')->assertStatus(503);
    }

    public function test_passes_through_when_no_entitlement_row_default_entitled(): void
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        // No entitlement row — LMS is entitled for ALL tenants by default.
        Sanctum::actingAs($user);

        $this->getJson('/_test/lms-gate')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_passes_through_when_org_entitled(): void
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        $menuItem = MenuItem::create([
            'menu_key' => 'lms',
            'label' => 'LMS',
            'href' => '/learn',
        ]);
        TenantModuleEntitlement::create([
            'org_id' => $org->id,
            'menu_id' => $menuItem->id,
            'is_entitled' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/_test/lms-gate')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_returns_403_when_entitlement_row_explicitly_false(): void
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        $menuItem = MenuItem::firstOrCreate(
            ['menu_key' => 'lms'],
            [
                'parent_menu_id' => null,
                'label' => 'Learn',
                'href' => '/learn',
                'icon' => 'GraduationCap',
                'section' => 'Menu Utama',
                'sort_order' => 100,
                'hideable' => true,
                'required_packages' => [],
            ]
        );

        TenantModuleEntitlement::create([
            'org_id' => $org->id,
            'menu_id' => $menuItem->id,
            'is_entitled' => false,   // explicit revoke
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/lms-gate')->assertStatus(403);
    }

    public function test_expired_grant_still_passes_since_lms_is_default_entitled(): void
    {
        // Under the default-entitled model, only an explicit is_entitled=false
        // revoke denies access. An is_entitled=true grant (even expired) is not a
        // revoke, so the tenant keeps the default LMS access.
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        $menuItem = MenuItem::firstOrCreate(
            ['menu_key' => 'lms'],
            [
                'parent_menu_id' => null,
                'label' => 'Learn',
                'href' => '/learn',
                'icon' => 'GraduationCap',
                'section' => 'Menu Utama',
                'sort_order' => 100,
                'hideable' => true,
                'required_packages' => [],
            ]
        );

        TenantModuleEntitlement::create([
            'org_id' => $org->id,
            'menu_id' => $menuItem->id,
            'is_entitled' => true,
            'valid_until' => now()->subDay()->toDateString(), // expired yesterday
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/lms-gate')->assertOk()->assertJson(['ok' => true]);
    }
}
