<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Models\Organization;

/**
 * Structural validation of `criteria_json` per `criteria_type`.
 * One sub-case per type proves BadgeCriteriaJsonRule is wired into both
 * StoreBadgeRequest (rules()) and UpdateBadgeRequest (controller-side).
 */
class BadgeCriteriaJsonValidationTest extends LmsAdminTestCase
{
    /**
     * Helper — build the minimal store payload with a custom criteria_json.
     */
    protected function payload(string $type, array $criteriaJson, string $slug = 't'): array
    {
        return [
            'slug'          => $slug,
            'name'          => 'N',
            'description'   => 'd',
            'icon'          => 'i',
            'criteria_type' => $type,
            'criteria_json' => $criteriaJson,
        ];
    }

    public function test_lesson_complete_requires_lesson_id_int(): void
    {
        $this->actingAsContentAdmin();

        // Missing lesson_id -> 422
        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'lesson_complete',
            ['theme' => 'blue', 'params' => []],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        // With lesson_id -> 201
        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'lesson_complete',
            ['theme' => 'blue', 'params' => ['lesson_id' => 42]],
            'lc-ok'
        ));
        $r2->assertCreated();
    }

    public function test_quiz_pass_and_quiz_perfect_require_quiz_id_int(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'quiz_pass',
            ['theme' => 'purple', 'params' => ['quiz_id' => 'not-an-int']],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'quiz_perfect',
            ['theme' => 'gold', 'params' => ['quiz_id' => 7]],
            'qp-ok'
        ));
        $r2->assertCreated();
    }

    public function test_course_complete_requires_course_id_int(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'course_complete',
            ['theme' => 'emerald', 'params' => []],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'course_complete',
            ['theme' => 'emerald', 'params' => ['course_id' => 9]],
            'cc-ok'
        ));
        $r2->assertCreated();
    }

    public function test_streak_requires_days_in_range(): void
    {
        $this->actingAsContentAdmin();

        // 0 -> below range
        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'streak',
            ['theme' => 'rose', 'params' => ['days' => 0]],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        // 366 -> above range
        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'streak',
            ['theme' => 'rose', 'params' => ['days' => 366]],
            's-bad'
        ));
        $r2->assertStatus(422);

        // 7 -> ok
        $r3 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'streak',
            ['theme' => 'rose', 'params' => ['days' => 7]],
            's-ok'
        ));
        $r3->assertCreated();
    }

    public function test_xp_threshold_requires_min_xp_in_range(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'xp_threshold',
            ['theme' => 'indigo', 'params' => ['min_xp' => 0]],
        ));
        $r->assertStatus(422);

        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'xp_threshold',
            ['theme' => 'indigo', 'params' => ['min_xp' => 1000]],
            'xt-ok'
        ));
        $r2->assertCreated();
    }

    public function test_theme_must_be_in_enum(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'custom',
            ['theme' => 'neon', 'params' => []],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);
    }

    public function test_params_must_be_object(): void
    {
        $this->actingAsContentAdmin();

        // params as a non-array (string) -> 422
        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'custom',
            ['theme' => 'blue', 'params' => 'not-an-object'],
        ));
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        // params missing entirely -> 422
        $r2 = $this->postJson('/api/lms/admin/badges', $this->payload(
            'custom',
            ['theme' => 'blue'],
            'p-miss'
        ));
        $r2->assertStatus(422);
    }

    public function test_custom_accepts_any_params_shape(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', $this->payload(
            'custom',
            ['theme' => 'gold', 'params' => ['kind' => 'whatever', 'nested' => ['a' => 1]]],
            'custom-ok'
        ));
        $r->assertCreated();
    }

    public function test_update_validates_against_effective_criteria_type(): void
    {
        // Existing badge: lesson_complete with lesson_id 5
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $badge = Badge::create([
            'org_id' => $org->id, 'slug' => 'lc-existing', 'name' => 'LC',
            'description' => 'd', 'icon' => 'i', 'criteria_type' => 'lesson_complete',
            'criteria_json' => ['theme' => 'blue', 'params' => ['lesson_id' => 5]],
        ]);

        // Update only criteria_json without lesson_id, keeping criteria_type
        // implicit -> the rule must use the existing criteria_type and reject.
        $r = $this->putJson("/api/lms/admin/badges/{$badge->id}", [
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['criteria_json']);

        // Same payload but switch criteria_type to custom -> should accept.
        $r2 = $this->putJson("/api/lms/admin/badges/{$badge->id}", [
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r2->assertOk();
        $r2->assertJsonPath('data.criteria_type', 'custom');
    }
}
