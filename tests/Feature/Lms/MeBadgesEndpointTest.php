<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Badge;
use App\Lms\Models\UserBadge;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Database\Seeders\LmsBadgesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeBadgesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function authedEntitled(): User
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        $this->seed(LmsBadgesSeeder::class);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_returns_earned_and_locked_arrays(): void
    {
        $user = $this->authedEntitled();
        $badge = Badge::where('slug', 'first-lesson')->first();
        UserBadge::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'badge_id' => $badge->id, 'awarded_at' => now()]);

        $r = $this->getJson('/api/lms/me/badges');
        $r->assertOk()->assertJsonStructure(['data' => ['earned', 'locked']]);

        $earnedSlugs = collect($r->json('data.earned'))->pluck('slug');
        $lockedSlugs = collect($r->json('data.locked'))->pluck('slug');
        $this->assertContains('first-lesson', $earnedSlugs);
        $this->assertNotContains('first-lesson', $lockedSlugs);
    }

    public function test_earned_includes_theme_from_criteria_json(): void
    {
        $user = $this->authedEntitled();
        $badge = Badge::where('slug', 'first-lesson')->first();
        UserBadge::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'badge_id' => $badge->id, 'awarded_at' => now()]);

        $r = $this->getJson('/api/lms/me/badges');
        $earned = collect($r->json('data.earned'))->firstWhere('slug', 'first-lesson');
        $this->assertEquals('blue', $earned['theme']);
        $this->assertEquals('BookOpen', $earned['icon']);
    }

    public function test_locked_includes_progress_for_completion_badges(): void
    {
        $this->authedEntitled();
        $r = $this->getJson('/api/lms/me/badges');
        $locked = collect($r->json('data.locked'))->firstWhere('slug', 'learner-novice');
        $this->assertNotNull($locked);
        $this->assertEquals(0, $locked['progress']['current']);
        $this->assertEquals(5, $locked['progress']['target']);
    }

    public function test_since_filter_returns_only_recent_earned(): void
    {
        $user = $this->authedEntitled();
        $oldBadge = Badge::where('slug', 'first-lesson')->first();
        $newBadge = Badge::where('slug', 'perfect-score')->first();

        UserBadge::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'badge_id' => $oldBadge->id, 'awarded_at' => now()->subHours(2)]);
        UserBadge::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'badge_id' => $newBadge->id, 'awarded_at' => now()->subMinutes(5)]);

        $since = now()->subHour()->toIso8601String();
        $r = $this->getJson("/api/lms/me/badges?since=" . urlencode($since));

        $r->assertOk();
        $earnedSlugs = collect($r->json('data.earned'))->pluck('slug');
        $this->assertContains('perfect-score', $earnedSlugs);
        $this->assertNotContains('first-lesson', $earnedSlugs);
        $this->assertArrayNotHasKey('locked', $r->json('data'));
    }
}
