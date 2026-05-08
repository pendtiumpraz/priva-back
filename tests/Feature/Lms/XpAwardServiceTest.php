<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\XpRule;
use App\Lms\Services\XpAwardService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XpAwardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_xp_log_and_upserts_leaderboard(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        XpRule::create(['action_key' => 'lesson.completed', 'xp_amount' => 10]);

        app(XpAwardService::class)->award($user, 'lesson.completed', 'lesson', '42');

        $this->assertDatabaseCount('lms_xp_log', 1);
        $this->assertDatabaseHas('lms_xp_log', [
            'user_id' => $user->id, 'org_id' => $org->id,
            'action' => 'lesson.completed', 'xp_amount' => 10,
            'ref_type' => 'lesson', 'ref_id' => '42',
        ]);
        $this->assertDatabaseHas('lms_org_leaderboard', [
            'user_id' => $user->id, 'org_id' => $org->id, 'xp_total' => 10,
        ]);
    }

    public function test_unknown_action_is_noop(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        app(XpAwardService::class)->award($user, 'nonexistent.action');

        $this->assertDatabaseCount('lms_xp_log', 0);
    }

    public function test_two_awards_accumulate(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        XpRule::create(['action_key' => 'lesson.completed', 'xp_amount' => 10]);
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);

        app(XpAwardService::class)->award($user, 'lesson.completed');
        app(XpAwardService::class)->award($user, 'quiz.passed');

        $this->assertDatabaseHas('lms_org_leaderboard', [
            'user_id' => $user->id, 'xp_total' => 60,
        ]);
    }

    public function test_award_runs_badge_evaluator(): void
    {
        $this->seed(\Database\Seeders\LmsBadgesSeeder::class);
        $org = \App\Models\Organization::factory()->create();
        $user = \App\Models\User::factory()->create(['org_id' => $org->id]);
        \App\Lms\Models\XpRule::create(['action_key' => 'lesson.completed', 'xp_amount' => 10]);

        $course = \App\Lms\Models\Course::create([
            'org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = \App\Lms\Models\Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $lesson = \App\Lms\Models\Lesson::create(['module_id' => $module->id, 'slug' => 'l', 'title' => 'L', 'body' => '', 'order' => 1]);
        \App\Lms\Models\UserLessonProgress::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'lesson_id' => $lesson->id, 'completed_at' => now(), 'watched_seconds' => 0,
        ]);

        app(\App\Lms\Services\XpAwardService::class)->award($user, 'lesson.completed');

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => \App\Lms\Models\Badge::where('slug', 'first-lesson')->value('id'),
        ]);
    }
}
