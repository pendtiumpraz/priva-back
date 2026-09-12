<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRopa;
use App\Models\VendorRopaEditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RoPA Pihak Ketiga — pihak ketiga mengisi catatan pemrosesannya sendiri lewat
 * tautan publik, lalu pengendali meninjau dan menautkannya ke RoPA sendiri.
 *
 * Janji yang dijaga di sini:
 *   1. tautan sekali-kirim: setelah dikirim, isinya terkunci;
 *   2. satu-satunya jalan koreksi adalah permintaan akses ubah — dan itu tetap
 *      bisa diajukan walau tautan sudah terkunci;
 *   3. persetujuan pengendali merotasi token: tautan lama mati, tautan baru hidup;
 *   4. tautan ke RoPA tidak pernah melintasi tenant.
 */
class VendorRopaPublicFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write', 'ropa:read', 'ropa:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));

        $this->vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
    }

    private function issueLink(): string
    {
        return $this->postJson("/api/vendor-risk/{$this->vendor->id}/ropa-link")
            ->assertOk()
            ->json('token');
    }

    /** @return array<string, mixed> */
    private function isi(array $override = []): array
    {
        return array_merge([
            'processing_activity' => 'Pengelolaan basis data nasabah',
            'purpose' => 'Menyimpan dan mencadangkan data nasabah untuk layanan perbankan',
            'role' => 'processor',
            'data_categories' => ['Nama', 'NIK'],
            'sub_processors' => [['name' => 'PT Pusat Data', 'country' => 'Indonesia', 'purpose' => 'Colocation']],
        ], $override);
    }

    public function test_pihak_ketiga_mengisi_lalu_mengirim_sekali(): void
    {
        $token = $this->issueLink();

        $this->getJson("/api/ropa-pihak-ketiga/{$token}")
            ->assertOk()
            ->assertJsonPath('data.pihak_ketiga.nama', 'PT Cloud Mitra')
            ->assertJsonPath('data.organisasi.nama', 'PT Nusantara Sejahtera')
            ->assertJsonPath('data.is_locked', false);

        $this->postJson("/api/ropa-pihak-ketiga/{$token}/draf", ['processing_activity' => 'Draf awal'])->assertOk();
        $this->assertSame('Draf awal', VendorRopa::first()->processing_activity);

        $this->postJson("/api/ropa-pihak-ketiga/{$token}/kirim", $this->isi())->assertOk();

        $vendorRopa = VendorRopa::first();
        $this->assertSame(VendorRopa::STATUS_SUBMITTED, $vendorRopa->status);
        $this->assertNotNull($vendorRopa->token_consumed_at);
        $this->assertSame(['Nama', 'NIK'], $vendorRopa->data_categories);
        $this->assertSame('PT Pusat Data', $vendorRopa->sub_processors[0]['name']);

        // Terkunci: perubahan lewat tautan yang sama ditolak.
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/draf", ['processing_activity' => 'Ubah diam-diam'])
            ->assertStatus(410);
        $this->assertSame('Pengelolaan basis data nasabah', VendorRopa::first()->processing_activity);

        $this->getJson("/api/ropa-pihak-ketiga/{$token}/hasil")->assertOk();
    }

    public function test_permintaan_akses_ubah_disetujui_merotasi_tautan(): void
    {
        $token = $this->issueLink();
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/kirim", $this->isi())->assertOk();

        // Terkunci, tetapi permintaan akses ubah tetap boleh diajukan.
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/minta-akses-ubah", ['reason' => 'Salah menulis masa retensi'])
            ->assertCreated();
        // Tidak menumpuk: permintaan kedua memakai antrean yang sama.
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/minta-akses-ubah", ['reason' => 'Masih salah'])->assertOk();
        $this->assertSame(1, VendorRopaEditRequest::count());

        $permintaan = VendorRopaEditRequest::first();
        $this->assertSame(VendorRopaEditRequest::STATUS_PENDING, $permintaan->status);

        $tokenBaru = $this->postJson("/api/vendor-ropas/permintaan-ubah/{$permintaan->id}/keputusan", ['action' => 'approve'])
            ->assertOk()
            ->json('data.public_url');
        $this->assertNotNull($tokenBaru);

        // Tautan lama mati, tautan baru hidup dan bisa diubah lagi.
        $this->getJson("/api/ropa-pihak-ketiga/{$token}")->assertStatus(404);
        $tokenBaru = VendorRopa::first()->access_token;
        $this->postJson("/api/ropa-pihak-ketiga/{$tokenBaru}/draf", ['processing_activity' => 'Perbaikan'])->assertOk();
        $this->assertSame('Perbaikan', VendorRopa::first()->processing_activity);
    }

    public function test_pengendali_meninjau_dan_menautkan_ke_ropa_sendiri(): void
    {
        $token = $this->issueLink();
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/kirim", $this->isi())->assertOk();
        $vendorRopa = VendorRopa::first();

        $this->postJson("/api/vendor-ropas/{$vendorRopa->id}/tinjau", ['action' => 'accept', 'notes' => 'Sesuai kontrak'])
            ->assertOk();
        $this->assertSame(VendorRopa::STATUS_ACCEPTED, $vendorRopa->fresh()->status);

        $milikSendiri = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Onboarding Nasabah',
        ]);
        $tetangga = Organization::factory()->create(['name' => 'PT Tetangga']);
        $milikTetangga = Ropa::create([
            'org_id' => $tetangga->id,
            'registration_number' => 'ROPA-2026-002',
            'processing_activity' => 'Milik tenant lain',
        ]);

        $this->putJson("/api/vendor-ropas/{$vendorRopa->id}/ropa", [
            'ropa_ids' => [$milikSendiri->id, $milikTetangga->id],
        ])->assertOk();

        $tertaut = $vendorRopa->fresh()->ropas()->pluck('ropas.id')->all();
        $this->assertSame([$milikSendiri->id], $tertaut);
    }

    public function test_tenant_lain_tidak_bisa_membuka_kiriman_ini(): void
    {
        $token = $this->issueLink();
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/kirim", $this->isi())->assertOk();
        $vendorRopa = VendorRopa::first();

        $tetangga = Organization::factory()->create(['name' => 'PT Tetangga']);
        $role = TenantRole::create([
            'org_id' => $tetangga->id,
            'name' => 'admin',
            'slug' => 'admin-tetangga-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $tetangga->id, 'tenant_role_id' => $role->id]));

        $this->getJson("/api/vendor-ropas/{$vendorRopa->id}")->assertStatus(404);
        $this->getJson('/api/vendor-ropas')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson("/api/vendor-ropas/{$vendorRopa->id}/tinjau", ['action' => 'accept'])->assertStatus(404);
    }
}
