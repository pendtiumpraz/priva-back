<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Badge;
use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizAttempt;
use App\Lms\Models\UserBadge;
use App\Lms\Models\UserLessonProgress;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\XpLog;
use App\Lms\Services\BadgeEvaluator;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\LmsBadgesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LmsBadgesSeeder::class);
    }

    private function makeUser(): User
    {
        $org = Organization::factory()->create();
        return User::factory()->create(['org_id' => $org->id]);
    }

    private function makeLesson(): Lesson
    {
        $course = Course::create([
            'org_id' => null, 'slug' => 'c'.uniqid(), 'title' => 'C', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        return Lesson::create(['module_id' => $module->id, 'slug' => 'l'.uniqid(), 'title' => 'L', 'body' => '', 'order' => 1]);
    }

    public function test_first_lesson_badge_awarded_after_one_lesson_completion(): void
    {
        $user = $this->makeUser();
        $lesson = $this->makeLesson();
        UserLessonProgress::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'lesson_id' => $lesson->id, 'completed_at' => now(), 'watched_seconds' => 0,
        ]);

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'first-lesson')->value('id'),
        ]);
    }

    public function test_first_lesson_idempotent_on_second_lesson(): void
    {
        $user = $this->makeUser();
        $l1 = $this->makeLesson();
        $l2 = $this->makeLesson();
        UserLessonProgress::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l1->id, 'completed_at' => now(), 'watched_seconds' => 0]);
        UserLessonProgress::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l2->id, 'completed_at' => now(), 'watched_seconds' => 0]);

        app(BadgeEvaluator::class)->evaluate($user);
        app(BadgeEvaluator::class)->evaluate($user);

        $count = UserBadge::where('user_id', $user->id)
            ->where('badge_id', Badge::where('slug', 'first-lesson')->value('id'))
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_xp_rookie_awarded_at_100_xp(): void
    {
        $user = $this->makeUser();
        XpLog::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'action' => 'lesson.completed', 'xp_amount' => 50, 'ref_type' => null, 'ref_id' => null]);
        XpLog::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'action' => 'lesson.completed', 'xp_amount' => 50, 'ref_type' => null, 'ref_id' => null]);

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'xp-rookie')->value('id'),
        ]);
    }

    public function test_perfect_score_awarded_after_one_perfect_attempt(): void
    {
        $user = $this->makeUser();
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        QuizAttempt::create([
            'user_id' => $user->id, 'org_id' => $user->org_id, 'quiz_id' => $quiz->id,
            'score' => 100, 'passed' => true, 'attempt_number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'answers' => [],
        ]);

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'perfect-score')->value('id'),
        ]);
    }

    public function test_perfect_streak_awarded_after_three_perfect_in_a_row(): void
    {
        $user = $this->makeUser();
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        for ($i = 1; $i <= 3; $i++) {
            QuizAttempt::create([
                'user_id' => $user->id, 'org_id' => $user->org_id, 'quiz_id' => $quiz->id,
                'score' => 100, 'passed' => true, 'attempt_number' => $i,
                'started_at' => now()->subMinutes(10 - $i),
                'submitted_at' => now()->subMinutes(10 - $i),
                'answers' => [],
            ]);
        }

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'perfect-streak')->value('id'),
        ]);
    }

    public function test_perfect_streak_not_awarded_when_interrupted(): void
    {
        $user = $this->makeUser();
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        $scores = [100, 80, 100, 100];   // last 3 = 80,100,100 → not all 100
        foreach ($scores as $i => $score) {
            QuizAttempt::create([
                'user_id' => $user->id, 'org_id' => $user->org_id, 'quiz_id' => $quiz->id,
                'score' => $score, 'passed' => $score >= 70, 'attempt_number' => $i + 1,
                'started_at' => now()->subMinutes(20 - $i),
                'submitted_at' => now()->subMinutes(20 - $i),
                'answers' => [],
            ]);
        }

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseMissing('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'perfect-streak')->value('id'),
        ]);
    }

    public function test_daily_streak_3_awarded_with_three_consecutive_days(): void
    {
        $user = $this->makeUser();
        for ($d = 0; $d <= 2; $d++) {
            XpLog::create([
                'user_id' => $user->id, 'org_id' => $user->org_id,
                'action' => 'lesson.completed', 'xp_amount' => 10,
                'ref_type' => null, 'ref_id' => null,
                'created_at' => now()->subDays($d),
            ]);
        }

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'daily-streak-3')->value('id'),
        ]);
    }

    public function test_uu_pdp_expert_awarded_after_course_complete_and_exam_90(): void
    {
        $user = $this->makeUser();
        $course = Course::create([
            'org_id' => null, 'slug' => 'kepatuhan-uu-pdp-fundamentals', 'title' => 'UU PDP', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        UserModuleProgress::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'module_id' => $module->id, 'status' => 'completed', 'completed_at' => now(),
        ]);
        $exam = Quiz::create(['owner_type' => 'course', 'owner_key' => (string) $course->id, 'passing_score' => 80]);
        QuizAttempt::create([
            'user_id' => $user->id, 'org_id' => $user->org_id, 'quiz_id' => $exam->id,
            'score' => 92, 'passed' => true, 'attempt_number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'answers' => [],
        ]);

        app(BadgeEvaluator::class)->evaluate($user);

        $this->assertDatabaseHas('lms_user_badges', [
            'user_id' => $user->id,
            'badge_id' => Badge::where('slug', 'uu-pdp-expert')->value('id'),
        ]);
    }

    public function test_progress_for_returns_completion_progress(): void
    {
        $user = $this->makeUser();
        $l1 = $this->makeLesson();
        UserLessonProgress::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l1->id, 'completed_at' => now(), 'watched_seconds' => 0]);

        $badge = Badge::where('slug', 'learner-novice')->first();
        $progress = app(BadgeEvaluator::class)->progressFor($user, $badge);

        $this->assertEquals(1, $progress['current']);
        $this->assertEquals(5, $progress['target']);
        $this->assertNotEmpty($progress['label']);
    }
}
