<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\XpLog;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /me/xp-stats — weekly XP rollup + source breakdown for the leaderboard
 * side widgets (previously mocked on the FE).
 */
class MeXpStatsTest extends TestCase
{
    use RefreshDatabase;

    private function authedUser(): User
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
        Sanctum::actingAs($user);
        return $user;
    }

    private function xp(User $u, string $action, int $amount, \Carbon\Carbon $at): void
    {
        XpLog::create([
            'user_id' => $u->id, 'org_id' => $u->org_id, 'action' => $action,
            'xp_amount' => $amount, 'ref_type' => null, 'ref_id' => null, 'created_at' => $at,
        ]);
    }

    public function test_weekly_has_seven_days_ending_today_with_sums(): void
    {
        $user = $this->authedUser();
        $this->xp($user, 'lesson.completed', 30, now());          // today
        $this->xp($user, 'quiz.passed', 20, now());               // today
        $this->xp($user, 'lesson.completed', 15, now()->subDays(2));
        $this->xp($user, 'lesson.completed', 999, now()->subDays(10)); // outside window

        $r = $this->getJson('/api/lms/me/xp-stats')->assertOk();

        $r->assertJsonCount(7, 'data.weekly');
        $weekly = $r->json('data.weekly');
        $this->assertSame(now()->toDateString(), $weekly[6]['date']);
        $this->assertSame(50, $weekly[6]['xp']);          // today's 30+20
        $this->assertSame(15, $weekly[4]['xp']);          // 2 days ago
        // 10-day-old row excluded from the 7-day total.
        $this->assertSame(65, $r->json('data.weekly_total'));
    }

    public function test_sources_grouped_by_category(): void
    {
        $user = $this->authedUser();
        $this->xp($user, 'lesson.completed', 100, now());
        $this->xp($user, 'quiz.passed', 40, now());
        $this->xp($user, 'quiz.perfect', 10, now());
        $this->xp($user, 'badge.earned', 25, now());

        $r = $this->getJson('/api/lms/me/xp-stats')->assertOk();
        $sources = collect($r->json('data.sources'))->keyBy('key');

        $this->assertSame(100, $sources['lesson']['xp']);
        $this->assertSame(50, $sources['quiz']['xp']); // 40 + 10
        $this->assertSame(25, $sources['badge']['xp']);
        $this->assertFalse($sources->has('streak')); // empty category omitted
        // pct is relative to the max source (lesson=100).
        $this->assertSame(100, $sources['lesson']['pct']);
        $this->assertSame(50, $sources['quiz']['pct']);
    }

    public function test_empty_when_no_xp(): void
    {
        $this->authedUser();
        $r = $this->getJson('/api/lms/me/xp-stats')->assertOk();
        $r->assertJsonCount(7, 'data.weekly');
        $r->assertJsonPath('data.weekly_total', 0);
        $r->assertJsonCount(0, 'data.sources');
    }
}
