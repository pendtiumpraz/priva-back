<?php

namespace Tests\Feature\Lms;

use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeStubsTest extends TestCase
{
    use RefreshDatabase;

    private function authedEntitledUser(): User
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
            'is_entitled' => true,
        ]);

        Sanctum::actingAs($user);
        return $user;
    }



    public function test_me_badges_returns_501_stub(): void
    {
        $this->authedEntitledUser();
        $this->getJson('/api/lms/me/badges')->assertStatus(501);
    }
}
