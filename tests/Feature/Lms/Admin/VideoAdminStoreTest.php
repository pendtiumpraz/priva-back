<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Video;

class VideoAdminStoreTest extends LmsAdminTestCase
{
    public function test_store_creates_youtube_video(): void
    {
        $user = $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/videos', [
            'source'           => 'youtube',
            'external_id'      => 'dQw4w9WgXcQ',
            'duration_seconds' => 213,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.source', 'youtube');
        $r->assertJsonPath('data.external_id', 'dQw4w9WgXcQ');
        $r->assertJsonPath('data.duration_seconds', 213);

        $this->assertDatabaseHas('lms_videos', [
            'source'      => 'youtube',
            'external_id' => 'dQw4w9WgXcQ',
            'uploaded_by' => $user->id,
        ]);
    }

    public function test_store_creates_mux_video(): void
    {
        $this->actingAsContentAdmin();

        $r = $this->postJson('/api/lms/admin/videos', [
            'source'      => 'mux',
            'external_id' => 'mux-asset-12345',
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.source', 'mux');
        $r->assertJsonPath('data.duration_seconds', null);
    }

    public function test_store_validation_errors(): void
    {
        $this->actingAsContentAdmin();

        // missing required fields
        $r1 = $this->postJson('/api/lms/admin/videos', []);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['source', 'external_id']);

        // bad source
        $r2 = $this->postJson('/api/lms/admin/videos', [
            'source'      => 'vimeo',
            'external_id' => 'x',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['source']);
    }

    public function test_index_returns_list_supports_search(): void
    {
        $this->actingAsContentAdmin();

        Video::create(['source' => 'youtube', 'external_id' => 'apple-1', 'duration_seconds' => 60]);
        Video::create(['source' => 'youtube', 'external_id' => 'banana-2', 'duration_seconds' => 60]);
        Video::create(['source' => 'mux',     'external_id' => 'cherry-3', 'duration_seconds' => 60]);

        $r = $this->getJson('/api/lms/admin/videos');
        $r->assertOk();
        $this->assertCount(3, $r->json('data'));

        $r2 = $this->getJson('/api/lms/admin/videos?search=banana');
        $r2->assertOk();
        $this->assertCount(1, $r2->json('data'));
        $this->assertSame('banana-2', $r2->json('data.0.external_id'));
    }
}
