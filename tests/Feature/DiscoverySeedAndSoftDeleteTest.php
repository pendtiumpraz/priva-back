<?php

namespace Tests\Feature;

use App\Models\DataCatalogLineage;
use App\Models\Organization;
use App\Models\PiiPatternRule;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\PiiPatternRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Katalog bawaan dan jaminan soft delete pada modul Data Discovery.
 *
 * Yang diuji di sini bukan "apakah endpointnya membalas 200", melainkan tiga
 * janji yang mudah rusak diam-diam: pola bawaan benar-benar tersemai dan
 * regexnya sah, penghapusan dapat dibatalkan, dan penghapusan yang disengaja
 * tidak dihidupkan lagi oleh sinkronisasi.
 */
class DiscoverySeedAndSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Admin Uji',
            'slug' => 'admin-uji',
            'permissions' => ['data_discovery:read', 'data_discovery:write'],
        ]);

        $this->user = User::factory()->create([
            'org_id' => $this->org->id,
            'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_setiap_pola_bawaan_mencocokkan_contoh_nilainya_sendiri(): void
    {
        // Pola yang salah tulis tetap sah secara sintaksis. Kesalahannya baru
        // ketahuan berbulan-bulan kemudian ketika ada yang menyadari kolomnya
        // tidak pernah terdeteksi — jadi diuji di sini, bukan di produksi.
        foreach (PiiPatternRuleService::defaults() as $item) {
            $result = @preg_match($item['pattern'], $item['sample_value']);

            $this->assertNotFalse(
                $result,
                "Pola '{$item['key']}' bukan ekspresi reguler yang sah.",
            );
            $this->assertSame(
                1,
                $result,
                "Pola '{$item['key']}' tidak mencocokkan contohnya sendiri: {$item['sample_value']}",
            );
        }
    }

    public function test_pola_bawaan_tersemai_saat_pertama_dibuka(): void
    {
        $this->assertSame(0, PiiPatternRule::count());

        $res = $this->getJson('/api/pii-patterns');

        $res->assertOk();
        $expected = count(PiiPatternRuleService::defaults());
        $this->assertSame($expected, PiiPatternRule::count());
        $this->assertSame($expected, $res->json('meta.seeded'));
    }

    public function test_penyemaian_tidak_menggandakan_saat_dibuka_berulang(): void
    {
        $this->getJson('/api/pii-patterns')->assertOk();
        $afterFirst = PiiPatternRule::count();

        $res = $this->getJson('/api/pii-patterns')->assertOk();

        $this->assertSame($afterFirst, PiiPatternRule::count());
        $this->assertSame(0, $res->json('meta.seeded'));
    }

    public function test_pola_yang_dihapus_tidak_tumbuh_lagi_saat_halaman_dibuka(): void
    {
        // Tenant yang sengaja membuang pola bawaan tidak boleh melihatnya
        // kembali setiap membuka halaman — kalau begitu, penghapusan tidak
        // pernah berarti apa-apa.
        $this->getJson('/api/pii-patterns')->assertOk();
        PiiPatternRule::query()->delete();

        $this->getJson('/api/pii-patterns')->assertOk();

        $this->assertSame(0, PiiPatternRule::count());
        $this->assertGreaterThan(0, PiiPatternRule::onlyTrashed()->count());
    }

    public function test_pola_dapat_disunting(): void
    {
        $this->getJson('/api/pii-patterns')->assertOk();
        $rule = PiiPatternRule::where('key', 'nik')->firstOrFail();

        $this->putJson("/api/pii-patterns/{$rule->id}", [
            'label' => 'NIK — disesuaikan',
            'is_active' => false,
        ])->assertOk();

        $rule->refresh();
        $this->assertSame('NIK — disesuaikan', $rule->label);
        $this->assertFalse($rule->is_active);
    }

    public function test_pola_dihapus_lunak_dan_dapat_dikembalikan(): void
    {
        $this->getJson('/api/pii-patterns')->assertOk();
        $rule = PiiPatternRule::where('key', 'npwp')->firstOrFail();

        $this->deleteJson("/api/pii-patterns/{$rule->id}")->assertOk();
        $this->assertSoftDeleted('pii_pattern_rules', ['id' => $rule->id]);

        $this->postJson("/api/pii-patterns/{$rule->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('pii_pattern_rules', ['id' => $rule->id]);
    }

    public function test_reset_mengembalikan_katalog_bawaan(): void
    {
        $this->getJson('/api/pii-patterns')->assertOk();
        PiiPatternRule::query()->delete();

        $res = $this->postJson('/api/pii-patterns/reset')->assertOk();

        $expected = count(PiiPatternRuleService::defaults());
        $this->assertSame($expected, PiiPatternRule::count());
        $this->assertSame(0, PiiPatternRule::onlyTrashed()->count());
        $this->assertSame($expected, $res->json('seeded'));
    }

    public function test_alamat_ip_disemai_nonaktif(): void
    {
        // Sengaja mati saat disemai: kolom log dan inventaris server penuh
        // alamat IP yang tidak menunjuk siapa pun, dan menyalakannya tanpa
        // dipikir menenggelamkan temuan yang benar-benar penting.
        $this->getJson('/api/pii-patterns')->assertOk();

        $this->assertFalse(PiiPatternRule::where('key', 'alamat_ip')->firstOrFail()->is_active);
        $this->assertTrue(PiiPatternRule::where('key', 'nik')->firstOrFail()->is_active);
    }

    public function test_tepi_silsilah_dihapus_lunak_dan_dapat_dikembalikan(): void
    {
        $edge = DataCatalogLineage::create([
            'org_id' => $this->org->id,
            'from_key' => 'system:a',
            'to_key' => 'system:b',
            'relation' => 'feeds',
            'source' => 'manual',
        ]);

        $this->deleteJson("/api/data-catalog/lineage/{$edge->id}")->assertOk();
        $this->assertSoftDeleted('data_catalog_lineage', ['id' => $edge->id]);

        $this->postJson("/api/data-catalog/lineage/{$edge->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('data_catalog_lineage', ['id' => $edge->id]);
    }
}
