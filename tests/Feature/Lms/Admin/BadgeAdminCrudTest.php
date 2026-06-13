<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Models\Organization;

class BadgeAdminCrudTest extends LmsAdminTestCase
{
    public function test_index_returns_own_org_and_global_badges_only(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        Badge::create([
            'org_id' => $org->id, 'slug' => 'mine', 'name' => 'Mine', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        Badge::create([
            'org_id' => null, 'slug' => 'global', 'name' => 'Global', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'gold', 'params' => []],
        ]);
        $other = Organization::factory()->create();
        Badge::create([
            'org_id' => $other->id, 'slug' => 'theirs', 'name' => 'Theirs', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'rose', 'params' => []],
        ]);

        $r = $this->getJson('/api/lms/admin/badges');
        $r->assertOk();

        $names = collect($r->json('data'))->pluck('name')->all();
        $this->assertContains('Mine', $names);
        $this->assertContains('Global', $names);
        $this->assertNotContains('Theirs', $names);

        $r->assertJsonStructure([
            'data' => [['id', 'slug', 'name', 'description', 'icon', 'criteria_type', 'criteria_json', 'is_seeded', 'awarded_count', 'org_id']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        // is_seeded === (org_id === null)
        $globalRow = collect($r->json('data'))->firstWhere('slug', 'global');
        $this->assertTrue($globalRow['is_seeded']);
        $mineRow = collect($r->json('data'))->firstWhere('slug', 'mine');
        $this->assertFalse($mineRow['is_seeded']);
    }

    public function test_store_creates_badge_with_default_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $r = $this->postJson('/api/lms/admin/badges', [
            'slug'          => 'first-step',
            'name'          => 'First Step',
            'description'   => 'Complete first lesson',
            'icon'          => 'BookOpen',
            'criteria_type' => 'lesson_complete',
            'criteria_json' => ['theme' => 'blue', 'params' => ['lesson_id' => 100]],
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.slug', 'first-step');
        $r->assertJsonPath('data.org_id', $org->id);
        $r->assertJsonPath('data.is_seeded', false);
        $r->assertJsonPath('data.criteria_type', 'lesson_complete');

        $this->assertDatabaseHas('lms_badges', [
            'slug'   => 'first-step',
            'org_id' => $org->id,
        ]);
    }

    public function test_store_validation_errors_on_missing_required_fields(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/badges', []);
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['slug', 'name', 'description', 'icon', 'criteria_type', 'criteria_json']);

        // bad criteria_type
        $r2 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'x', 'name' => 'X', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'unknown_type',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['criteria_type']);
    }

    public function test_show_returns_badge_with_timestamps(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $badge = Badge::create([
            'org_id' => $org->id, 'slug' => 'show-me', 'name' => 'Show Me', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);

        $r = $this->getJson("/api/lms/admin/badges/{$badge->id}");
        $r->assertOk();
        $r->assertJsonPath('data.id', $badge->id);
        $r->assertJsonPath('data.slug', 'show-me');
        $r->assertJsonStructure(['data' => ['id', 'slug', 'name', 'created_at', 'updated_at']]);
    }

    public function test_update_modifies_own_org_badge(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $badge = Badge::create([
            'org_id' => $org->id, 'slug' => 'mine', 'name' => 'Mine', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);

        $r = $this->putJson("/api/lms/admin/badges/{$badge->id}", [
            'name' => 'Mine Renamed',
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.name', 'Mine Renamed');
        $this->assertDatabaseHas('lms_badges', ['id' => $badge->id, 'name' => 'Mine Renamed']);
    }

    public function test_slug_uniqueness_is_per_org(): void
    {
        $orgA = Organization::factory()->create();
        $this->actingAsContentAdmin($orgA);

        $r1 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'shared', 'name' => 'A', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r1->assertCreated();

        // Same admin, same org -> 422
        $r2 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'shared', 'name' => 'B', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['slug']);

        // Different org -> allowed
        $orgB = Organization::factory()->create();
        $this->actingAsContentAdmin($orgB);

        $r3 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'shared', 'name' => 'B', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r3->assertCreated();
        $this->assertEquals(2, Badge::where('slug', 'shared')->count());
    }

    public function test_cross_org_show_returns_404(): void
    {
        // Org A admin
        $orgA = Organization::factory()->create();
        $this->actingAsContentAdmin($orgA);

        // Other-org badge
        $orgB = Organization::factory()->create();
        $other = Badge::create([
            'org_id' => $orgB->id, 'slug' => 'other', 'name' => 'Other', 'description' => 'd',
            'icon' => 'i', 'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);

        $r = $this->getJson("/api/lms/admin/badges/{$other->id}");
        $r->assertStatus(404);
    }

    public function test_can_recreate_slug_after_soft_delete(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $r1 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'recyclable', 'name' => 'A', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r1->assertCreated();
        $oldId = $r1->json('data.id');

        $this->deleteJson("/api/lms/admin/badges/{$oldId}")->assertNoContent();
        $this->assertSoftDeleted('lms_badges', ['id' => $oldId]);

        $r2 = $this->postJson('/api/lms/admin/badges', [
            'slug' => 'recyclable', 'name' => 'B', 'description' => 'd', 'icon' => 'i',
            'criteria_type' => 'custom',
            'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $r2->assertCreated();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $r = $this->getJson('/api/lms/admin/badges');
        $r->assertStatus(401);
    }
}
