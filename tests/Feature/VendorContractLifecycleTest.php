<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SecurityAlert;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\AlertEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak pihak ketiga & daur hidupnya.
 *
 * Janji yang dijaga di sini:
 *   1. pihak ketiga tidak bisa diaktifkan tanpa kontrak berlaku yang berkasnya ada;
 *   2. kontrak bisa datang dari dua arah — diunggah perusahaan, atau oleh pihak
 *      ketiga lewat tautan sekali-unggah;
 *   3. kontrak bisa diteruskan ke Contract Review tanpa mengunggah ulang berkas;
 *   4. kerja sama tidak bisa ditutup sebelum seluruh langkah offboarding tuntas;
 *   5. kontrak tenant lain tidak pernah terlihat.
 */
class VendorContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));

        $this->vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
    }

    private function pdf(string $name = 'kontrak.pdf'): UploadedFile
    {
        // Berkas dengan magic byte PDF asli — FileUploadValidator memeriksa isi,
        // bukan sekadar ekstensi.
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    private function buatKontrak(array $override = []): string
    {
        return $this->postJson("/api/vendor-risk/{$this->vendor->id}/contracts", array_merge([
            'title' => 'PKS Layanan Awan 2026',
            'contract_type' => 'dpa',
            'start_at' => now()->subMonth()->toDateString(),
            'end_at' => now()->addYear()->toDateString(),
        ], $override))->assertCreated()->json('data.id');
    }

    public function test_aktivasi_ditolak_tanpa_kontrak_berkas(): void
    {
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/lifecycle", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('butuh', 'kontrak');

        // Kontrak tercatat tetapi berkasnya belum ada → tetap ditolak.
        $this->buatKontrak();
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/lifecycle", ['status' => 'active'])->assertStatus(422);

        $this->assertSame(Vendor::LIFECYCLE_PROSPECTIVE, $this->vendor->fresh()->lifecycle_status);
    }

    public function test_perusahaan_unggah_kontrak_lalu_pihak_ketiga_bisa_aktif(): void
    {
        $contractId = $this->buatKontrak();

        $this->postJson("/api/vendor-contracts/{$contractId}/berkas", ['file' => $this->pdf()])->assertOk();
        $this->assertSame(VendorContract::SIDE_TENANT, VendorContract::find($contractId)->uploaded_side);

        $this->postJson("/api/vendor-risk/{$this->vendor->id}/lifecycle", ['status' => 'active'])->assertOk();

        $vendor = $this->vendor->fresh();
        $this->assertSame(Vendor::LIFECYCLE_ACTIVE, $vendor->lifecycle_status);
        $this->assertNotNull($vendor->activated_at);
    }

    public function test_pihak_ketiga_mengunggah_kontrak_lewat_tautan_sekali_pakai(): void
    {
        $contractId = $this->buatKontrak();

        $url = $this->postJson("/api/vendor-contracts/{$contractId}/tautan-unggah")->assertOk()->json('public_url');
        $token = VendorContract::find($contractId)->access_token;
        $this->assertStringContainsString($token, $url);

        $this->getJson("/api/kontrak-pihak-ketiga/{$token}")
            ->assertOk()
            ->assertJsonPath('data.pihak_ketiga.nama', 'PT Cloud Mitra')
            ->assertJsonPath('data.sudah_diunggah', false);

        $this->postJson("/api/kontrak-pihak-ketiga/{$token}/unggah", ['file' => $this->pdf('pks.pdf')])->assertOk();

        $contract = VendorContract::find($contractId);
        $this->assertSame(VendorContract::SIDE_THIRD_PARTY, $contract->uploaded_side);
        $this->assertNull($contract->uploaded_by, 'unggahan lewat tautan publik tidak mengklaim pengguna mana pun');
        $this->assertTrue($contract->has_file);

        // Sekali unggah: percobaan kedua ditolak.
        $this->postJson("/api/kontrak-pihak-ketiga/{$token}/unggah", ['file' => $this->pdf('pks-2.pdf')])->assertStatus(410);
    }

    public function test_kontrak_diteruskan_ke_contract_review_tanpa_unggah_ulang(): void
    {
        $contractId = $this->buatKontrak();
        $this->postJson("/api/vendor-contracts/{$contractId}/berkas", ['file' => $this->pdf()])->assertOk();

        $reviewId = $this->postJson("/api/vendor-contracts/{$contractId}/kirim-telaah")
            ->assertCreated()
            ->json('data.contract_review_id');

        $review = DB::table('contract_reviews')->where('id', $reviewId)->first();
        $this->assertNotNull($review);
        $this->assertSame($this->org->id, $review->org_id);
        $this->assertSame(VendorContract::find($contractId)->file['path'], $review->file_path);

        // Tidak menggandakan telaah untuk kontrak yang sama.
        $this->postJson("/api/vendor-contracts/{$contractId}/kirim-telaah")->assertOk();
        $this->assertSame(1, DB::table('contract_reviews')->count());
    }

    public function test_offboarding_wajib_tuntas_sebelum_kerja_sama_ditutup(): void
    {
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/lifecycle", ['status' => 'offboarding'])->assertOk();
        $checklist = $this->vendor->fresh()->offboarding_checklist;
        $this->assertNotEmpty($checklist, 'masuk offboarding harus menyiapkan daftar periksa');

        $this->postJson("/api/vendor-risk/{$this->vendor->id}/offboarding/selesai", ['reason' => 'Kontrak tidak diperpanjang'])
            ->assertStatus(422)
            ->assertJsonStructure(['belum_selesai']);

        $steps = array_map(fn ($s) => ['key' => $s['key'], 'done' => true], $checklist);
        $this->putJson("/api/vendor-risk/{$this->vendor->id}/offboarding", ['steps' => $steps])->assertOk();

        $this->postJson("/api/vendor-risk/{$this->vendor->id}/offboarding/selesai", ['reason' => 'Kontrak tidak diperpanjang'])
            ->assertOk();

        $vendor = $this->vendor->fresh();
        $this->assertSame(Vendor::LIFECYCLE_TERMINATED, $vendor->lifecycle_status);
        $this->assertNotNull($vendor->terminated_at);
        $this->assertSame('Kontrak tidak diperpanjang', $vendor->termination_reason);
    }

    public function test_kontrak_yang_akan_berakhir_memunculkan_peringatan(): void
    {
        $segera = $this->buatKontrak(['title' => 'PKS Segera Berakhir', 'end_at' => now()->addDays(10)->toDateString()]);
        $masihLama = $this->buatKontrak(['title' => 'PKS Masih Lama', 'end_at' => now()->addDays(200)->toDateString()]);

        app(AlertEngineService::class)->runAllRules($this->org->id);

        $this->assertTrue(
            SecurityAlert::where('org_id', $this->org->id)->where('rule_code', 'contract_expiring')->where('record_id', $segera)->exists(),
            'kontrak yang berakhir 10 hari lagi harus memunculkan peringatan',
        );
        $this->assertFalse(
            SecurityAlert::where('org_id', $this->org->id)->where('record_id', $masihLama)->exists(),
            'kontrak yang masih lama tidak boleh ikut memunculkan peringatan',
        );
    }

    public function test_kontrak_tenant_lain_tidak_terlihat(): void
    {
        $contractId = $this->buatKontrak();

        $tetangga = Organization::factory()->create(['name' => 'PT Tetangga']);
        $role = TenantRole::create([
            'org_id' => $tetangga->id,
            'name' => 'admin',
            'slug' => 'admin-tetangga-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $tetangga->id, 'tenant_role_id' => $role->id]));

        $this->getJson('/api/vendor-contracts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/vendor-contracts/{$contractId}/kirim-telaah")->assertStatus(404);
    }
}
