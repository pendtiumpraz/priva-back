<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Konfigurasi widget consent — GET/PUT /api/consent-collections/{id}/widget-config.
 *
 * Endpoint ini ada supaya tab "Widget" di dashboard bisa menyimpan konfigurasi
 * secara PARSIAL tanpa menimpa seluruh kolom `settings` (yang akan terjadi
 * kalau memakai PUT /api/m/consent/{id}, karena universal CRUD melakukan
 * replace penuh atas payload `settings`).
 */
class ConsentWidgetConfigTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private ConsentCollectionPoint $cpA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::create(['name' => 'Org A', 'slug' => 'org-a-'.Str::random(6)]);
        $this->orgB = Organization::create(['name' => 'Org B', 'slug' => 'org-b-'.Str::random(6)]);

        $this->adminA = User::factory()->create(['org_id' => $this->orgA->id, 'role' => 'admin']);

        $this->cpA = ConsentCollectionPoint::create([
            'org_id' => $this->orgA->id,
            'collection_id' => 'CNT-2026-001',
            'name' => 'Homepage Banner',
            'kind' => ConsentCollectionPoint::KIND_COOKIE,
            'display_mode' => 'banner_bottom',
            'display_frequency' => 'once',
            'audience' => 'anonymous_only',
            'locale' => 'id',
            'settings' => [
                'logo_url' => 'https://cdn.example.com/logo.png',
                'guardian_mode' => true,
                'guardian_label' => 'Nama Wali',
                'primary_color' => '#0f172a',
            ],
        ]);
    }

    public function test_widget_config_read_returns_stored_values_not_defaults(): void
    {
        Sanctum::actingAs($this->adminA);

        $res = $this->getJson("/api/consent-collections/{$this->cpA->id}/widget-config");

        $res->assertOk()
            ->assertJsonPath('data.display_mode', 'banner_bottom')
            ->assertJsonPath('data.audience', 'anonymous_only')
            ->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.settings.primary_color', '#0f172a')
            ->assertJsonPath('data.settings.logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('data.settings.guardian_mode', true)
            ->assertJsonPath('data.settings.guardian_label', 'Nama Wali');

        // Secret tidak pernah dibalikkan ke UI — hanya flag terpasang/tidak.
        $res->assertJsonMissingPath('data.captcha_secret');
        $res->assertJsonPath('data.captcha_secret_set', false);
    }

    public function test_widget_config_saved_and_read_back(): void
    {
        Sanctum::actingAs($this->adminA);

        $payload = [
            'display_mode' => 'modal_center',
            'display_frequency' => 'session',
            'audience' => 'both',
            'locale' => 'en',
            'allowed_domains' => ['example.com', 'www.example.com'],
            'auth_methods' => ['widget' => true, 'api_key' => true],
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => 'site-key-123',
            'settings' => [
                'primary_color' => '#123456',
                'accent_color' => '#abcdef',
                'banner_text' => 'Kami memakai cookie.',
                'modal_intro_text' => 'Atur preferensi Anda.',
                'show_powered_by' => false,
                'guardian_relation_options' => 'Orang Tua,Wali Sah',
            ],
        ];

        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", $payload)
            ->assertOk()
            ->assertJsonPath('data.display_mode', 'modal_center')
            ->assertJsonPath('data.settings.banner_text', 'Kami memakai cookie.');

        // Read-back lewat endpoint GET → harus persis nilai yang disimpan.
        $res = $this->getJson("/api/consent-collections/{$this->cpA->id}/widget-config");
        $res->assertOk()
            ->assertJsonPath('data.display_mode', 'modal_center')
            ->assertJsonPath('data.display_frequency', 'session')
            ->assertJsonPath('data.audience', 'both')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.allowed_domains', ['example.com', 'www.example.com'])
            ->assertJsonPath('data.auth_methods.api_key', true)
            ->assertJsonPath('data.captcha_provider', 'turnstile')
            ->assertJsonPath('data.captcha_site_key', 'site-key-123')
            ->assertJsonPath('data.settings.primary_color', '#123456')
            ->assertJsonPath('data.settings.accent_color', '#abcdef')
            ->assertJsonPath('data.settings.modal_intro_text', 'Atur preferensi Anda.')
            ->assertJsonPath('data.settings.show_powered_by', false)
            ->assertJsonPath('data.settings.guardian_relation_options', 'Orang Tua,Wali Sah');

        // Key settings yang TIDAK dikirim tidak boleh hilang (merge, bukan replace).
        $res->assertJsonPath('data.settings.logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('data.settings.guardian_mode', true)
            ->assertJsonPath('data.settings.guardian_label', 'Nama Wali');
    }

    public function test_widget_config_writes_audit_log(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", [
            'display_mode' => 'fullscreen',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'consent',
            'record_id' => $this->cpA->id,
            'action' => 'consent_collection.widget_config_update',
            'user_id' => $this->adminA->id,
        ]);
    }

    public function test_widget_config_rejects_invalid_values(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", [
            'display_mode' => 'floating_unicorn',
        ])->assertStatus(422);

        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", [
            'settings' => ['primary_color' => 'not-a-hex'],
        ])->assertStatus(422);

        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", [
            'locale' => 'fr',
        ])->assertStatus(422);

        // Tidak ada yang berubah setelah semua penolakan di atas.
        $this->assertSame('banner_bottom', $this->cpA->fresh()->display_mode);
    }

    public function test_widget_config_is_org_scoped(): void
    {
        $adminB = User::factory()->create(['org_id' => $this->orgB->id, 'role' => 'admin']);
        Sanctum::actingAs($adminB);

        $this->getJson("/api/consent-collections/{$this->cpA->id}/widget-config")->assertStatus(404);
        $this->putJson("/api/consent-collections/{$this->cpA->id}/widget-config", [
            'display_mode' => 'fullscreen',
        ])->assertStatus(404);

        $this->assertSame('banner_bottom', $this->cpA->fresh()->display_mode);
    }
}
