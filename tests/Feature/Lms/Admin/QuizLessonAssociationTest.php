<?php

namespace Tests\Feature\Lms\Admin;

use App\Models\Organization;

/**
 * Spec §3.6 talks about lesson_id; the real schema attaches quizzes to
 * modules (or courses, or feature_doc sections). These tests document the
 * mapping at the boundary and the cross-org rejection path.
 */
class QuizLessonAssociationTest extends LmsAdminTestCase
{
    public function test_quiz_can_be_created_for_course_owner(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'cl');

        $r = $this->postJson('/api/lms/admin/quizzes', [
            'course_id'     => $course->id,
            'title'         => 'Final Exam',
            'passing_score' => 75,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.owner_type', 'course');
        $r->assertJsonPath('data.owner_key', (string) $course->id);
    }

    public function test_quiz_for_foreign_org_module_rejected(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $other = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($other, 'fo');
        $foreignModule = $this->makeModule($foreignCourse, 'fm');

        $r = $this->postJson('/api/lms/admin/quizzes', [
            'module_id'     => $foreignModule->id,
            'title'         => 'Hijack',
            'passing_score' => 70,
        ]);

        // assertMutable on the foreign org's course -> 403
        $r->assertStatus(403);
        $this->assertDatabaseMissing('lms_quizzes', ['title' => 'Hijack']);
    }
}
