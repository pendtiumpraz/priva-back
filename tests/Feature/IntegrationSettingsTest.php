<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET|PUT /api/integrations/settings — kontrak legacy (flat, 4 kunci) yang
 * masih dipakai layar Breach (frontend loadIntegrations/saveIntegrations).
 * Regression guard: method getSettings/updateSettings sempat terhapus pada
 * commit 16f1c68 sementara route-nya tetap terdaftar → 500 di HEAD.
 */
class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'PT Uji Integrasi', 'slug' => 'uji-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Admin',
            'permissions' => ['*'],
        ]);
        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'Admin Uji',
            'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'tenant_role_id' => $role->id,
        ]);
        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function get_settings_mengembalikan_empat_kunci_dengan_default_string_kosong(): void
    {
        $res = $this->getJson('/api/integrations/settings');

        $res->assertOk()->assertExactJson([
            'data' => [
                'telegram_bot_token' => '',
                'telegram_chat_id' => '',
                'siem_webhook_url' => '',
                'soar_webhook_url' => '',
            ],
        ]);
    }

    #[Test]
    public function get_settings_membaca_nilai_tersimpan_apa_adanya_tanpa_masking(): void
    {
        $this->org->update(['settings' => [
            'telegram_bot_token' => '123456:ABC-DEF1234ghIkl',
            'telegram_chat_id' => '-1001234567890',
            'siem_webhook_url' => 'https://siem.contoh.id/api/webhook',
            'soar_webhook_url' => 'https://soar.contoh.id/playbook/trigger',
        ]]);

        $this->getJson('/api/integrations/settings')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'telegram_bot_token' => '123456:ABC-DEF1234ghIkl',
                    'telegram_chat_id' => '-1001234567890',
                    'siem_webhook_url' => 'https://siem.contoh.id/api/webhook',
                    'soar_webhook_url' => 'https://soar.contoh.id/playbook/trigger',
                ],
            ]);
    }

    #[Test]
    public function put_settings_round_trip_lewat_get(): void
    {
        $payload = [
            'telegram_bot_token' => '987654:XYZ-token',
            'telegram_chat_id' => '-100999',
            'siem_webhook_url' => 'https://siem.contoh.id/hec',
            'soar_webhook_url' => 'https://soar.contoh.id/hook',
        ];

        $this->putJson('/api/integrations/settings', $payload)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Integration settings saved.',
            ]);

        $this->getJson('/api/integrations/settings')
            ->assertOk()
            ->assertExactJson(['data' => $payload]);
    }

    #[Test]
    public function put_settings_hanya_menimpa_kunci_yang_dikirim(): void
    {
        $this->org->update(['settings' => [
            'telegram_bot_token' => 'token-lama',
            'telegram_chat_id' => 'chat-lama',
            'crm_connections' => ['salesforce'],
        ]]);

        $this->putJson('/api/integrations/settings', [
            'telegram_chat_id' => 'chat-baru',
        ])->assertOk();

        $this->org->refresh();

        // Kunci yang tidak dikirim tetap utuh, termasuk kunci non-integrasi.
        $this->assertSame('token-lama', $this->org->settings['telegram_bot_token']);
        $this->assertSame('chat-baru', $this->org->settings['telegram_chat_id']);
        $this->assertSame(['salesforce'], $this->org->settings['crm_connections']);
    }

    #[Test]
    public function endpoint_settings_butuh_autentikasi(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/integrations/settings')->assertUnauthorized();
        $this->putJson('/api/integrations/settings', [])->assertUnauthorized();
    }
}
