<?php

namespace Tests\Feature;

use App\Models\CrmCredential;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the five /api/crm-credentials routes.
 *
 * These routes fatal-ed at HEAD because the controller called
 * `TenantContextService::currentOrgId()`, a method that never existed
 * (recorded in phpstan-baseline.neon as method.notFound). Org is now resolved
 * the way every sibling controller in the authenticated group resolves it:
 * `$request->user()->org_id`.
 *
 * Beyond "does not 500", the important guarantee here is org scoping — another
 * tenant's credential must be invisible (index) and unreachable (show/update/
 * destroy/probe → 404, never 403-with-leak or a cross-tenant write).
 */
class CrmCredentialControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $admin;

    private CrmCredential $mine;

    private CrmCredential $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'PT Tenant Satu',
            'slug' => 'tenant-satu-'.Str::random(6),
        ]);

        $this->otherOrg = Organization::create([
            'name' => 'PT Tenant Dua',
            'slug' => 'tenant-dua-'.Str::random(6),
        ]);

        // role=admin lolos legacy fallback CheckPermission (tenantRole->permissions
        // bukan array) sehingga permission:consent,read|write terpenuhi.
        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);

        $this->mine = CrmCredential::create([
            'org_id' => $this->org->id,
            'provider' => CrmCredential::PROVIDER_WEBHOOK,
            'label' => 'Webhook Produksi',
            'api_key' => 'secret-key-ABCD',
            'endpoint_url' => 'https://hook.example.test/consent',
        ]);

        $this->theirs = CrmCredential::create([
            'org_id' => $this->otherOrg->id,
            'provider' => CrmCredential::PROVIDER_WEBHOOK,
            'label' => 'Webhook Tetangga',
            'api_key' => 'secret-key-WXYZ',
            'endpoint_url' => 'https://hook.other.test/consent',
        ]);
    }

    // ── index ────────────────────────────────────────────────

    public function test_index_returns_only_own_org_credentials(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/crm-credentials');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($this->mine->id, $ids);
        $this->assertNotContains($this->theirs->id, $ids, 'Kredensial org lain bocor di index.');
    }

    public function test_index_never_echoes_plaintext_secrets(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/crm-credentials');

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $this->mine->id);

        $this->assertArrayNotHasKey('api_key', $row);
        $this->assertArrayNotHasKey('api_secret', $row);
        $this->assertSame('••••ABCD', $row['api_key_masked']);
        $res->assertDontSee('secret-key-ABCD');
    }

    // ── store ────────────────────────────────────────────────

    public function test_store_creates_credential_scoped_to_caller_org(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/crm-credentials', [
            'provider' => CrmCredential::PROVIDER_HUBSPOT,
            'label' => 'HubSpot Produksi',
            'api_key' => 'pat-na1-000111',
        ]);

        $res->assertStatus(201);

        $created = CrmCredential::findOrFail($res->json('data.id'));
        $this->assertSame($this->org->id, $created->org_id);
        $this->assertSame('pat-na1-000111', $created->api_key);
        $this->assertNotNull($created->rotated_at);
    }

    public function test_store_rejects_unknown_provider(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/crm-credentials', ['provider' => 'pipedrive'])
            ->assertStatus(422);
    }

    public function test_store_upserts_on_same_org_provider_label(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/crm-credentials', [
            'provider' => CrmCredential::PROVIDER_WEBHOOK,
            'label' => 'Webhook Produksi',
            'endpoint_url' => 'https://hook.example.test/v2',
        ]);

        $res->assertStatus(201);
        $this->assertSame($this->mine->id, $res->json('data.id'));
        $this->assertSame(
            1,
            CrmCredential::where('org_id', $this->org->id)->where('provider', 'webhook')->count()
        );
    }

    // ── update ───────────────────────────────────────────────

    public function test_update_changes_own_credential(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->putJson("/api/crm-credentials/{$this->mine->id}", [
            'api_key' => 'rotated-key-9999',
            'is_active' => false,
        ]);

        $res->assertOk();

        $this->mine->refresh();
        $this->assertSame('rotated-key-9999', $this->mine->api_key);
        $this->assertFalse($this->mine->is_active);
        $this->assertNotNull($this->mine->rotated_at);
    }

    public function test_update_cannot_touch_another_orgs_credential(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/crm-credentials/{$this->theirs->id}", ['is_active' => false])
            ->assertStatus(404);

        $this->theirs->refresh();
        $this->assertTrue($this->theirs->is_active, 'Kredensial org lain termodifikasi lintas tenant.');
    }

    // ── destroy ──────────────────────────────────────────────

    public function test_destroy_soft_deletes_own_credential(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/crm-credentials/{$this->mine->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSoftDeleted('crm_credentials', ['id' => $this->mine->id]);
    }

    public function test_destroy_cannot_reach_another_orgs_credential(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/crm-credentials/{$this->theirs->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('crm_credentials', [
            'id' => $this->theirs->id,
            'deleted_at' => null,
        ]);
    }

    // ── probe ────────────────────────────────────────────────

    public function test_probe_returns_connector_result(): void
    {
        Http::fake(['hook.example.test/*' => Http::response('', 200)]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/crm-credentials/{$this->mine->id}/probe")
            ->assertOk()
            ->assertJson(['data' => ['ok' => true, 'status' => 200]]);
    }

    public function test_probe_returns_422_when_connector_throws(): void
    {
        $noUrl = CrmCredential::create([
            'org_id' => $this->org->id,
            'provider' => CrmCredential::PROVIDER_WEBHOOK,
            'label' => 'Webhook Tanpa URL',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/crm-credentials/{$noUrl->id}/probe")
            ->assertStatus(422)
            ->assertJson(['error' => 'Webhook endpoint_url missing']);
    }

    public function test_probe_cannot_reach_another_orgs_credential(): void
    {
        Http::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/crm-credentials/{$this->theirs->id}/probe")
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    // ── auth ─────────────────────────────────────────────────

    public function test_routes_require_authentication(): void
    {
        $this->getJson('/api/crm-credentials')->assertStatus(401);
        $this->postJson('/api/crm-credentials', [])->assertStatus(401);
    }
}
