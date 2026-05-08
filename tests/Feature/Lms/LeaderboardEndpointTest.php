<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\OrgLeaderboard;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaderboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrg(): array
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        return [$org, $mi];
    }

    public function test_returns_top_20_ordered_by_xp(): void
    {
        [$org] = $this->setupOrg();
        for ($i = 1; $i <= 25; $i++) {
            $u = User::factory()->create(['org_id' => $org->id, 'name' => "User $i"]);
            OrgLeaderboard::create([
                'org_id' => $org->id, 'user_id' => $u->id,
                'xp_total' => 1000 - ($i * 10), 'badges_count' => 0,
                'courses_completed' => 0, 'computed_at' => now(),
            ]);
        }
        $caller = User::factory()->create(['org_id' => $org->id]);
        OrgLeaderboard::create(['org_id' => $org->id, 'user_id' => $caller->id, 'xp_total' => 5, 'badges_count' => 0, 'courses_completed' => 0, 'computed_at' => now()]);
        Sanctum::actingAs($caller);

        $r = $this->getJson('/api/lms/leaderboard');
        $r->assertOk()->assertJsonStructure(['data' => ['top', 'current_user']]);
        $this->assertCount(20, $r->json('data.top'));
        $first = $r->json('data.top.0');
        $this->assertEquals(990, $first['xp_total']);
        $this->assertEquals(1, $first['rank']);
    }

    public function test_current_user_in_top_marked_in_top_true(): void
    {
        [$org] = $this->setupOrg();
        $u1 = User::factory()->create(['org_id' => $org->id]);
        OrgLeaderboard::create(['org_id' => $org->id, 'user_id' => $u1->id, 'xp_total' => 100, 'badges_count' => 0, 'courses_completed' => 0, 'computed_at' => now()]);
        Sanctum::actingAs($u1);

        $r = $this->getJson('/api/lms/leaderboard');
        $r->assertOk();
        $this->assertTrue($r->json('data.current_user.in_top'));
        $this->assertEquals(1, $r->json('data.current_user.rank'));
    }

    public function test_current_user_outside_top_marked_in_top_false(): void
    {
        [$org] = $this->setupOrg();
        for ($i = 1; $i <= 25; $i++) {
            $u = User::factory()->create(['org_id' => $org->id]);
            OrgLeaderboard::create(['org_id' => $org->id, 'user_id' => $u->id, 'xp_total' => 1000 - ($i * 10), 'badges_count' => 0, 'courses_completed' => 0, 'computed_at' => now()]);
        }
        $caller = User::factory()->create(['org_id' => $org->id]);
        OrgLeaderboard::create(['org_id' => $org->id, 'user_id' => $caller->id, 'xp_total' => 5, 'badges_count' => 0, 'courses_completed' => 0, 'computed_at' => now()]);
        Sanctum::actingAs($caller);

        $r = $this->getJson('/api/lms/leaderboard');
        $r->assertOk();
        $this->assertFalse($r->json('data.current_user.in_top'));
        $this->assertEquals(26, $r->json('data.current_user.rank'));
    }
}
