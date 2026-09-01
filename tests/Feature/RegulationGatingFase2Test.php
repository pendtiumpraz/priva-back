<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\TenantRole;
use App\Models\User;
use Database\Seeders\RegulationFrameworkSeeder;
use Database\Seeders\RegulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 2: gating framework GAP + bank pertanyaan TPRM oleh regulasi add-on.
 *
 * UU PDP (framework uupdp / library pdp_compliance) = core → selalu tampil.
 * GDPR/PDPA → hanya tampil bila add-on di-enable tenant.
 */
class RegulationGatingFase2Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegulationSeeder::class);
        $this->seed(RegulationFrameworkSeeder::class);

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Admin',
            'permissions' => ['gap_assessment:read', 'vendor_risk:read', 'settings:read', 'settings:write'],
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

    private function enable(string $code): void
    {
        $this->putJson("/api/regulation-addons/{$code}", ['enabled' => true])->assertOk();
    }

    #[Test]
    public function framework_gap_gdpr_tersembunyi_sampai_addon_aktif(): void
    {
        Sanctum::actingAs($this->user);

        $codes = fn () => collect($this->getJson('/api/gap/regulations')->assertOk()->json('data'))->pluck('code')->all();

        $before = $codes();
        $this->assertContains('uupdp', $before, 'Framework UU PDP (core) harus selalu tampil');
        $this->assertNotContains('gdpr', $before, 'GDPR harus tersembunyi saat add-on OFF');
        $this->assertNotContains('pdpa', $before);

        $this->enable('gdpr');
        $after = $codes();
        $this->assertContains('gdpr', $after);
        $this->assertContains('uupdp', $after);
        $this->assertNotContains('pdpa', $after, 'PDPA masih OFF');
    }

    #[Test]
    public function bank_pertanyaan_tprm_digating_oleh_regulasi(): void
    {
        Sanctum::actingAs($this->user);

        $this->lib('lib-pdp', 'pdp_compliance', 'uu_pdp');
        $this->lib('lib-gdpr', 'gdpr', 'gdpr');
        $this->lib('lib-netral', 'custom', null);

        $slugs = fn () => collect($this->getJson('/api/tprm/libraries')->assertOk()->json('data'))->pluck('slug')->all();

        $before = $slugs();
        $this->assertContains('lib-pdp', $before, 'Library PDP (core) selalu tampil');
        $this->assertContains('lib-netral', $before, 'Library tanpa kode selalu tampil');
        $this->assertNotContains('lib-gdpr', $before, 'Library GDPR tersembunyi saat OFF');

        $this->enable('gdpr');
        $this->assertContains('lib-gdpr', $slugs());
    }

    private function lib(string $slug, string $category, ?string $regCode): void
    {
        QuestionLibrary::create([
            'org_id' => null,
            'name' => strtoupper($slug),
            'slug' => $slug,
            'category' => $category,
            'regulation_code' => $regCode,
            'version' => 'v1',
            'source' => 'seeded',
            'is_active' => true,
            'is_locked' => true,
        ]);
    }
}
