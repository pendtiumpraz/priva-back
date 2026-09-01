<?php

namespace Tests\Feature;

use App\Models\KnowledgeBaseSection;
use App\Models\Organization;
use App\Models\Regulation;
use App\Models\TenantRole;
use App\Models\User;
use Database\Seeders\RegulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regulation add-on per tenant (Fase 1).
 *
 * Yang menentukan: UU PDP + PP 33 selalu aktif & terkunci; add-on default OFF
 * dan bisa di-toggle; gating KB menyembunyikan konten add-on yang belum aktif
 * tanpa pernah menyentuh konten core/umum.
 */
class RegulationAddonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegulationSeeder::class);

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Admin',
            'permissions' => ['settings:read', 'settings:write'],
        ]);
        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'Admin Uji',
            'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function kb(string $key, ?string $regCode, string $category = 'regulation'): void
    {
        KnowledgeBaseSection::create([
            'org_id' => null,
            'module_key' => $key,
            'title' => strtoupper($key),
            'content' => 'x',
            'keywords' => '',
            'category' => $category,
            'regulation_code' => $regCode,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function daftar_regulasi_menandai_core_terkunci_dan_addon_default_off(): void
    {
        Sanctum::actingAs($this->user);
        $data = collect($this->getJson('/api/regulation-addons')->assertOk()->json('data'))->keyBy('code');

        $this->assertCount(7, $data);
        $this->assertTrue($data['uu_pdp']['enabled']);
        $this->assertTrue($data['uu_pdp']['locked']);
        $this->assertTrue($data['pp_33']['enabled'] && $data['pp_33']['locked']);
        $this->assertFalse($data['gdpr']['enabled']);
        $this->assertFalse($data['gdpr']['locked']);
    }

    #[Test]
    public function tenant_dapat_mengaktifkan_addon(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/regulation-addons/gdpr', ['enabled' => true])->assertOk();

        $data = collect($this->getJson('/api/regulation-addons')->json('data'))->keyBy('code');
        $this->assertTrue($data['gdpr']['enabled']);
    }

    #[Test]
    public function regulasi_core_tidak_bisa_dinonaktifkan(): void
    {
        Sanctum::actingAs($this->user);
        $this->putJson('/api/regulation-addons/uu_pdp', ['enabled' => false])->assertStatus(422);
        $this->putJson('/api/regulation-addons/pp_33', ['enabled' => false])->assertStatus(422);
    }

    #[Test]
    public function kb_menyembunyikan_konten_addon_yang_belum_aktif(): void
    {
        Sanctum::actingAs($this->user);
        $this->kb('uupdp_pasal_1', 'uu_pdp');
        $this->kb('pp33_pasal_1', 'pp_33');
        $this->kb('gdpr_art_5', 'gdpr');
        $this->kb('general', null, 'general');

        $keys = fn () => collect($this->getJson('/api/knowledge-base')->json('data'))->pluck('module_key')->all();

        $before = $keys();
        $this->assertContains('uupdp_pasal_1', $before);
        $this->assertContains('pp33_pasal_1', $before);
        $this->assertContains('general', $before);
        $this->assertNotContains('gdpr_art_5', $before, 'GDPR harus tersembunyi saat add-on OFF');

        // Aktifkan GDPR → muncul.
        $this->putJson('/api/regulation-addons/gdpr', ['enabled' => true])->assertOk();
        $this->assertContains('gdpr_art_5', $keys());
    }

    #[Test]
    public function tanpa_izin_settings_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Viewer',
            'permissions' => ['ropa:read'],
        ]);
        $viewer = User::create([
            'org_id' => $this->org->id,
            'name' => 'Viewer',
            'email' => 'v'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'viewer',
            'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($viewer);
        $this->putJson('/api/regulation-addons/gdpr', ['enabled' => true])->assertStatus(403);
        $this->assertSame(0, Regulation::where('code', 'nonexistent')->count());
    }
}
