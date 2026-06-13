<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Models\Organization;

class ContentAdminPermissionTest extends LmsAdminTestCase
{
    public function test_unauthenticated_quiz_endpoints_return_401(): void
    {
        $this->getJson('/api/lms/admin/quizzes')->assertStatus(401);
        $this->postJson('/api/lms/admin/quizzes', [])->assertStatus(401);
        $this->getJson('/api/lms/admin/quizzes/1')->assertStatus(401);
        $this->getJson('/api/lms/admin/quizzes/1/questions')->assertStatus(401);
        $this->postJson('/api/lms/admin/videos', [])->assertStatus(401);
        $this->getJson('/api/lms/admin/videos')->assertStatus(401);
    }

    public function test_learner_role_blocked_on_each_endpoint_group(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'lc');
        $module = $this->makeModule($course, 'lm');
        $quiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'L', 'passing_score' => 70,
        ]);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'p',
            'options' => [['key' => 'a', 'label' => 'A', 'is_correct' => true]],
            'correct_answer' => ['a'], 'order' => 1, 'points' => 1,
        ]);

        // Quizzes
        $this->getJson('/api/lms/admin/quizzes')->assertStatus(403);
        $this->postJson('/api/lms/admin/quizzes', [])->assertStatus(403);
        $this->getJson("/api/lms/admin/quizzes/{$quiz->id}")->assertStatus(403);
        $this->putJson("/api/lms/admin/quizzes/{$quiz->id}", [])->assertStatus(403);
        $this->deleteJson("/api/lms/admin/quizzes/{$quiz->id}")->assertStatus(403);

        // Questions
        $this->getJson("/api/lms/admin/quizzes/{$quiz->id}/questions")->assertStatus(403);
        $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [])->assertStatus(403);
        $this->getJson("/api/lms/admin/questions/{$q->id}")->assertStatus(403);
        $this->putJson("/api/lms/admin/questions/{$q->id}", [])->assertStatus(403);
        $this->deleteJson("/api/lms/admin/questions/{$q->id}")->assertStatus(403);

        // Videos
        $this->getJson('/api/lms/admin/videos')->assertStatus(403);
        $this->postJson('/api/lms/admin/videos', [])->assertStatus(403);
    }
}
