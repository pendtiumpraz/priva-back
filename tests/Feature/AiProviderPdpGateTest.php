<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gerbang kepatuhan PDP pada pemilihan provider AI.
 *
 * Kolom pdp_risk / no_training sebelumnya hanya metadata tampilan: tidak ada
 * yang mencegah tenant memilih provider yang catatannya sendiri berbunyi
 * "JANGAN untuk data pribadi". Test ini mengunci perilaku barunya.
 */
class AiProviderPdpGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'id' => (string) Str::uuid(),
            'name' => 'PT Uji Kepatuhan',
            'slug' => 'uji-kepatuhan-'.Str::random(6),
        ]);
    }

    private function user(string $role): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'org_id' => $this->org->id,
            'name' => ucfirst($role).' Uji',
            'email' => $role.'-'.Str::random(6).'@contoh.test',
            'password' => bcrypt('rahasia-uji'),
            'role' => $role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $compliance
     */
    private function provider(string $name, array $compliance = []): AiProvider
    {
        $provider = AiProvider::create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'api_base_url' => 'https://api.contoh.test/v1',
            'is_active' => true,
        ], $compliance));

        return $provider->refresh();
    }

    private function model(AiProvider $provider): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'name' => 'Model Uji',
            'model_id' => 'uji-1',
            'is_active' => true,
        ]);
    }

    /** Kunci API wajib ada supaya pengujian berhenti di gerbang PDP, bukan di cek kunci. */
    private function giveApiKey(AiProvider $provider): void
    {
        DB::table('ai_provider_configs')->insert([
            'org_id' => $this->org->id,
            'provider_id' => $provider->id,
            'api_key_encrypted' => encrypt('kunci-uji'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------

    public function test_provider_aman_tetap_dapat_dipilih(): void
    {
        $provider = $this->provider('Provider Aman', [
            'pdp_risk' => 'safe', 'no_training' => true, 'zdr_available' => true,
            'dpa_url' => 'https://contoh.test/dpa',
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('ai_active_selections', [
            'org_id' => $this->org->id,
            'chat_provider_id' => $provider->id,
        ]);
    }

    public function test_provider_not_recommended_ditolak_untuk_tenant(): void
    {
        $provider = $this->provider('Provider Berisiko', [
            'pdp_risk' => 'not_recommended', 'no_training' => false,
            'zdr_available' => false, 'jurisdiction' => 'Tiongkok',
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        $res = $this->actingAs($this->user('admin'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('override_available', false)
            ->assertJsonFragment(['pdp_risk' => 'not_recommended']);

        // Yang penting: tidak ada yang tersimpan.
        $this->assertDatabaseMissing('ai_active_selections', [
            'org_id' => $this->org->id,
            'chat_provider_id' => $provider->id,
        ]);
    }

    public function test_provider_yang_melatih_model_ditolak_walau_risiko_tidak_ditandai(): void
    {
        // pdp_risk sengaja dikosongkan — no_training=false saja sudah cukup
        // untuk menolak, karena data yang terkirim tidak bisa ditarik lagi.
        $provider = $this->provider('Provider Melatih', [
            'no_training' => false,
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
            ])
            ->assertStatus(422);
    }

    public function test_tenant_tidak_bisa_menembus_dengan_accept_pdp_risk(): void
    {
        $provider = $this->provider('Provider Berisiko', [
            'pdp_risk' => 'not_recommended', 'no_training' => false,
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        // Admin tenant mengirim bendera penembusan — tetap harus ditolak.
        $this->actingAs($this->user('admin'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
                'accept_pdp_risk' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('ai_active_selections', [
            'org_id' => $this->org->id,
            'chat_provider_id' => $provider->id,
        ]);
    }

    public function test_root_dapat_menembus_secara_sadar_dan_tercatat(): void
    {
        $provider = $this->provider('Provider Berisiko', [
            'pdp_risk' => 'not_recommended', 'no_training' => false,
            'jurisdiction' => 'Tiongkok',
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        $this->actingAs($this->user('root'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
                'accept_pdp_risk' => true,
            ])
            ->assertOk();

        // Penembusan wajib meninggalkan jejak, bukan lolos diam-diam.
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'ai_provider',
            'action' => 'pdp_risk_override',
            'record_id' => (string) $provider->id,
        ]);
    }

    public function test_root_tanpa_bendera_tetap_ditolak_tetapi_diberi_tahu_caranya(): void
    {
        $provider = $this->provider('Provider Berisiko', [
            'pdp_risk' => 'not_recommended', 'no_training' => false,
        ]);
        $model = $this->model($provider);
        $this->giveApiKey($provider);

        $this->actingAs($this->user('root'))
            ->postJson('/api/ai-providers/set-active', [
                'mode' => 'chat',
                'provider_id' => $provider->id,
                'model_id' => $model->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('override_available', true);
    }
}
