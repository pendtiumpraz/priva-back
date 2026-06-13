<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Video;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/lms/videos/{id}/playback-token (Mux signed playback, M1).
 *
 * Uses a throwaway RSA keypair injected into config so the JWT path runs
 * without real Mux credentials. The returned token must verify against the
 * matching public key.
 */
class VideoPlaybackTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $publicPem;

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $this->publicPem = openssl_pkey_get_details($res)['key'];

        config([
            'lms.enabled' => true,
            'services.mux.signing_key_id' => 'test-signing-key',
            'services.mux.signing_key_private_key' => $privatePem, // raw PEM, used as-is
            'services.mux.playback_token_ttl' => 3600,
        ]);
    }

    private function actAsEntitledUser(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        Sanctum::actingAs($user);
    }

    public function test_signed_mux_video_returns_verifiable_token(): void
    {
        $this->actAsEntitledUser();
        $video = Video::create(['source' => 'mux', 'external_id' => 'plbk_demo_123', 'playback_policy' => 'signed', 'duration_seconds' => null]);

        $r = $this->getJson("/api/lms/videos/{$video->id}/playback-token");

        $r->assertOk()->assertJsonStructure(['playback_id', 'token', 'expires_at']);
        $r->assertJsonPath('playback_id', 'plbk_demo_123');

        $decoded = JWT::decode($r->json('token'), new Key($this->publicPem, 'RS256'));
        $this->assertSame('plbk_demo_123', $decoded->sub);
        $this->assertSame('v', $decoded->aud);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 3600, $r->json('expires_at'), 5);
    }

    public function test_youtube_video_does_not_get_a_token(): void
    {
        $this->actAsEntitledUser();
        $video = Video::create(['source' => 'youtube', 'external_id' => 'jNQXAC9IVRw', 'playback_policy' => 'public', 'duration_seconds' => 19]);

        $this->getJson("/api/lms/videos/{$video->id}/playback-token")
            ->assertStatus(422)->assertJsonPath('code', 'LMS_VIDEO_NOT_SIGNED');
    }

    public function test_public_mux_video_does_not_get_a_token(): void
    {
        $this->actAsEntitledUser();
        $video = Video::create(['source' => 'mux', 'external_id' => 'plbk_public', 'playback_policy' => 'public', 'duration_seconds' => null]);

        $this->getJson("/api/lms/videos/{$video->id}/playback-token")
            ->assertStatus(422)->assertJsonPath('code', 'LMS_VIDEO_NOT_SIGNED');
    }

    public function test_returns_503_when_signing_not_configured(): void
    {
        $this->actAsEntitledUser();
        config(['services.mux.signing_key_id' => null, 'services.mux.signing_key_private_key' => null]);
        $video = Video::create(['source' => 'mux', 'external_id' => 'plbk_x', 'playback_policy' => 'signed', 'duration_seconds' => null]);

        $this->getJson("/api/lms/videos/{$video->id}/playback-token")
            ->assertStatus(503)->assertJsonPath('code', 'LMS_MUX_NOT_CONFIGURED');
    }
}
