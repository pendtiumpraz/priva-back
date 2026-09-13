<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRopaEditRequest;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tautan publik harus menunjuk HALAMAN, bukan API.
 *
 * Halaman pengisian RoPA pihak ketiga dilayani Next.js
 * (frontend/src/app/ropa-pihak-ketiga/[token]). Ketika tautannya dibangun dari
 * APP_URL — host Laravel — ia terbentuk rapi, tersalin rapi, dan baru gagal di
 * tangan penerimanya sebagai 404. Kegagalan yang tidak berbunyi di sisi kita
 * itulah yang membuat uji ini ada.
 */
class TautanPublikFrontendTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.contoh.id';

    private const HALAMAN = 'https://app.contoh.id';

    private Organization $org;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::API,
            'app.frontend_url' => self::HALAMAN,
            'app.frontend_url_explicit' => true,
        ]);

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

    // ------------------------------------------------------------- inti bug

    public function test_tautan_ropa_pihak_ketiga_memakai_host_halaman_bukan_host_api(): void
    {
        $url = $this->postJson("/api/vendor-risk/{$this->vendor->id}/ropa-link")
            ->assertOk()
            ->json('public_url');

        $this->assertStringStartsWith(self::HALAMAN.'/ropa-pihak-ketiga/', $url);
        $this->assertStringNotContainsString(self::API, $url);
    }

    public function test_persetujuan_permintaan_ubah_juga_memakai_host_halaman(): void
    {
        $token = $this->postJson("/api/vendor-risk/{$this->vendor->id}/ropa-link")->assertOk()->json('token');

        $isi = [
            'processing_activity' => 'Pengelolaan basis data nasabah',
            'purpose' => 'Menyimpan dan mencadangkan data nasabah',
            'role' => 'processor',
            'data_categories' => ['Nama', 'NIK'],
        ];

        $this->postJson("/api/ropa-pihak-ketiga/{$token}/draf", $isi)->assertOk();
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/kirim", $isi)->assertOk();
        $this->postJson("/api/ropa-pihak-ketiga/{$token}/minta-akses-ubah", ['reason' => 'Salah retensi'])
            ->assertCreated();

        $permintaan = VendorRopaEditRequest::first();
        $url = $this->postJson("/api/vendor-ropas/permintaan-ubah/{$permintaan->id}/keputusan", ['action' => 'approve'])
            ->assertOk()
            ->json('data.public_url');

        $this->assertStringStartsWith(self::HALAMAN.'/ropa-pihak-ketiga/', $url);
        $this->assertStringNotContainsString(self::API, $url);
    }

    // -------------------------------------------------------- peringatannya

    public function test_peringatan_muncul_saat_frontend_url_belum_diset(): void
    {
        // Keadaan sebenarnya ketika FRONTEND_URL tidak ada di .env: config
        // menjatuhkannya ke APP_URL, dan tautannya menunjuk host API.
        config([
            'app.frontend_url' => self::API,
            'app.frontend_url_explicit' => false,
        ]);

        $res = $this->postJson("/api/vendor-risk/{$this->vendor->id}/ropa-link")->assertOk();

        $peringatan = $res->json('url_warning');
        $this->assertNotNull($peringatan);
        $this->assertStringContainsString('FRONTEND_URL', $peringatan);
        $this->assertStringContainsString(self::API, $peringatan);
    }

    public function test_tidak_ada_peringatan_saat_frontend_url_diset(): void
    {
        $this->assertNull(
            $this->postJson("/api/vendor-risk/{$this->vendor->id}/ropa-link")->assertOk()->json('url_warning')
        );
    }

    /**
     * Pemasangan satu host ikut diperingatkan selama FRONTEND_URL belum
     * dinyatakan — disengaja: konfigurasi yang dinyatakan terang lebih baik
     * daripada yang kebetulan benar.
     */
    public function test_satu_host_tetap_diperingatkan_bila_belum_dinyatakan(): void
    {
        config([
            'app.url' => self::HALAMAN,
            'app.frontend_url' => self::HALAMAN,
            'app.frontend_url_explicit' => false,
        ]);

        $this->assertNotNull(FrontendUrl::peringatan());

        config(['app.frontend_url_explicit' => true]);
        $this->assertNull(FrontendUrl::peringatan());
    }

    // ------------------------------------------------------------- pembantu

    public function test_penyusun_tautan_tidak_menghasilkan_garis_miring_ganda(): void
    {
        config(['app.frontend_url' => self::HALAMAN.'/']);

        $this->assertSame(self::HALAMAN.'/ropa-pihak-ketiga/abc', FrontendUrl::link('/ropa-pihak-ketiga/abc'));
        $this->assertSame(self::HALAMAN.'/ropa-pihak-ketiga/abc', FrontendUrl::link('ropa-pihak-ketiga/abc'));
    }
}
