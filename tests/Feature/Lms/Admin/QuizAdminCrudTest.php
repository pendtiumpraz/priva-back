<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizAttempt;
use App\Lms\Models\QuizQuestion;
use App\Models\Organization;
use App\Models\User;

class QuizAdminCrudTest extends LmsAdminTestCase
{
    public function test_index_returns_own_org_and_global_quizzes_only(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'mine');
        $module = $this->makeModule($course, 'm1');
        $mineQuiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'Mine', 'passing_score' => 70,
        ]);

        $globalCourse = $this->makeCourse(null, 'glob');
        $globalModule = $this->makeModule($globalCourse, 'gm1');
        $globalQuiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $globalModule->id,
            'title' => 'Global', 'passing_score' => 70,
        ]);

        $other = Organization::factory()->create();
        $otherCourse = $this->makeCourse($other, 'other');
        $otherModule = $this->makeModule($otherCourse, 'om1');
        Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $otherModule->id,
            'title' => 'Theirs', 'passing_score' => 70,
        ]);

        // attempts on mineQuiz
        $attempter = User::factory()->create(['org_id' => $org->id]);
        QuizAttempt::create([
            'user_id' => $attempter->id, 'org_id' => $org->id, 'quiz_id' => $mineQuiz->id,
            'score' => 80, 'passed' => true, 'attempt_number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'answers' => [],
        ]);

        $r = $this->getJson('/api/lms/admin/quizzes');
        $r->assertOk();

        $titles = collect($r->json('data'))->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertContains('Global', $titles);
        $this->assertNotContains('Theirs', $titles);

        $r->assertJsonStructure([
            'data' => [['id', 'title', 'owner_type', 'owner_key', 'passing_score', 'questions_count', 'attempt_count']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        // Verify attempt_count populated for mineQuiz
        $mineRow = collect($r->json('data'))->firstWhere('title', 'Mine');
        $this->assertSame(1, $mineRow['attempt_count']);
    }

    public function test_store_creates_quiz_for_module_owner(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'host');
        $module = $this->makeModule($course, 'm1');

        $r = $this->postJson('/api/lms/admin/quizzes', [
            'module_id'       => $module->id,
            'title'           => 'My Quiz',
            'passing_score'   => 80,
            'time_limit_mins' => 30,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.title', 'My Quiz');
        $r->assertJsonPath('data.owner_type', 'module');
        $r->assertJsonPath('data.owner_key', (string) $module->id);
        $r->assertJsonPath('data.passing_score', 80);
        $r->assertJsonPath('data.time_limit_mins', 30);

        $this->assertDatabaseHas('lms_quizzes', [
            'owner_type'         => 'module',
            'owner_key'          => (string) $module->id,
            'title'              => 'My Quiz',
            'passing_score'      => 80,
            'time_limit_seconds' => 1800,
        ]);
    }

    public function test_store_validation_errors(): void
    {
        $this->actingAsContentAdmin();

        // missing passing_score + missing owner pair
        $r1 = $this->postJson('/api/lms/admin/quizzes', ['title' => 'X']);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['passing_score']);

        // passing_score out of range
        $org = Organization::factory()->create();
        $course = $this->makeCourse($org, 'pr');
        $module = $this->makeModule($course, 'prm');

        $r2 = $this->postJson('/api/lms/admin/quizzes', [
            'module_id'     => $module->id,
            'passing_score' => 150,
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['passing_score']);

        // owner_type without owner_key
        $r3 = $this->postJson('/api/lms/admin/quizzes', [
            'owner_type'    => 'module',
            'passing_score' => 70,
        ]);
        $r3->assertStatus(422);
        $r3->assertJsonValidationErrors(['owner_type']);
    }

    public function test_show_returns_quiz_with_questions_array(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'sh');
        $module = $this->makeModule($course, 'sm');
        $quiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'Q', 'passing_score' => 70,
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'P1',
            'options' => [['key' => 'a', 'label' => 'A', 'is_correct' => true]],
            'correct_answer' => ['a'], 'order' => 1, 'points' => 1,
        ]);

        $r = $this->getJson("/api/lms/admin/quizzes/{$quiz->id}");
        $r->assertOk();
        $r->assertJsonPath('data.id', $quiz->id);
        $r->assertJsonPath('data.title', 'Q');
        $r->assertJsonCount(1, 'data.questions');
        $r->assertJsonPath('data.questions.0.type', 'mcq');
        $r->assertJsonPath('data.questions.0.question_text', 'P1');
    }

    public function test_update_modifies_fields(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'up');
        $module = $this->makeModule($course, 'um');
        $quiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'Old', 'passing_score' => 60,
        ]);

        $r = $this->putJson("/api/lms/admin/quizzes/{$quiz->id}", [
            'title'           => 'New',
            'passing_score'   => 90,
            'time_limit_mins' => 45,
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.title', 'New');
        $r->assertJsonPath('data.passing_score', 90);
        $r->assertJsonPath('data.time_limit_mins', 45);

        $this->assertDatabaseHas('lms_quizzes', [
            'id'                 => $quiz->id,
            'title'              => 'New',
            'passing_score'      => 90,
            'time_limit_seconds' => 2700,
        ]);
    }

    public function test_destroy_returns_204_and_cascades_questions(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'd');
        $module = $this->makeModule($course, 'dm');
        $quiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'D', 'passing_score' => 70,
        ]);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'p',
            'options' => [['key' => 'a', 'label' => 'A', 'is_correct' => true]],
            'correct_answer' => ['a'], 'order' => 1, 'points' => 1,
        ]);

        $r = $this->deleteJson("/api/lms/admin/quizzes/{$quiz->id}");
        $r->assertNoContent();
        $this->assertDatabaseMissing('lms_quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseMissing('lms_quiz_questions', ['id' => $q->id]);
    }

    public function test_cross_org_show_returns_404(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $other = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($other, 'fo');
        $foreignModule = $this->makeModule($foreignCourse, 'fm');
        $foreignQuiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $foreignModule->id,
            'title' => 'Foreign', 'passing_score' => 70,
        ]);

        $this->getJson("/api/lms/admin/quizzes/{$foreignQuiz->id}")->assertStatus(404);
        $this->putJson("/api/lms/admin/quizzes/{$foreignQuiz->id}", ['passing_score' => 50])
            ->assertStatus(404);
        $this->deleteJson("/api/lms/admin/quizzes/{$foreignQuiz->id}")->assertStatus(404);
    }

    public function test_global_parent_blocks_tenant_mutation(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $globalCourse = $this->makeCourse(null, 'glob');
        $globalModule = $this->makeModule($globalCourse, 'gm');
        $globalQuiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $globalModule->id,
            'title' => 'G', 'passing_score' => 70,
        ]);

        // Tenant admin can SEE global quiz
        $this->getJson("/api/lms/admin/quizzes/{$globalQuiz->id}")->assertOk();

        // ...but cannot UPDATE / DELETE it
        $this->putJson("/api/lms/admin/quizzes/{$globalQuiz->id}", ['passing_score' => 30])
            ->assertStatus(403);
        $this->deleteJson("/api/lms/admin/quizzes/{$globalQuiz->id}")->assertStatus(403);
    }
}
