<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\XpRule;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_exam_attempt_grades_via_owner_type_course(): void
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
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);
        XpRule::create(['action_key' => 'quiz.perfect', 'xp_amount' => 25]);
        Sanctum::actingAs($user);

        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $exam = Quiz::create(['owner_type' => 'course', 'owner_key' => (string) $course->id, 'passing_score' => 80]);
        $q = QuizQuestion::create(['quiz_id' => $exam->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->postJson("/api/lms/courses/c/exam/attempts", [
            'answers' => [(string) $q->id => ['a']],
        ]);
        $r->assertOk()->assertJsonPath('data.passed', true);
        $this->assertDatabaseHas('lms_quiz_attempts', ['quiz_id' => $exam->id, 'passed' => true]);
    }

    public function test_exam_attempt_blocked_when_modules_incomplete(): void
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

        $course = Course::create(['org_id' => null, 'slug' => 'cx', 'title' => 'CX', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 2, 'created_by' => null]);
        Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        $exam = Quiz::create(['owner_type' => 'course', 'owner_key' => (string) $course->id, 'passing_score' => 80]);
        $q = QuizQuestion::create(['quiz_id' => $exam->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        // No UserModuleProgress rows — module not completed.
        $r = $this->postJson("/api/lms/courses/cx/exam/attempts", [
            'answers' => [(string) $q->id => ['a']],
        ]);
        $r->assertForbidden()->assertJsonPath('code', 'LMS_EXAM_LOCKED');
    }

    public function test_exam_attempt_allowed_when_all_modules_completed(): void
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
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);
        XpRule::create(['action_key' => 'quiz.perfect', 'xp_amount' => 25]);
        Sanctum::actingAs($user);

        $course = Course::create(['org_id' => null, 'slug' => 'cy', 'title' => 'CY', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 3, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'description' => '', 'order' => 1]);
        UserModuleProgress::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'module_id' => $module->id, 'status' => 'completed', 'completed_at' => now()]);
        $exam = Quiz::create(['owner_type' => 'course', 'owner_key' => (string) $course->id, 'passing_score' => 80]);
        $q = QuizQuestion::create(['quiz_id' => $exam->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->postJson("/api/lms/courses/cy/exam/attempts", [
            'answers' => [(string) $q->id => ['a']],
        ]);
        $r->assertOk()->assertJsonPath('data.passed', true);
    }
}
