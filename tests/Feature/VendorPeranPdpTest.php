<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kategorisasi peran pihak ketiga menurut UU PDP.
 *
 * Janji yang dijaga di sini:
 *   1. peran dibaca dari pivot `ropa_vendor` (per kegiatan), bukan dari kolom
 *      peran bawaan — satu perusahaan bisa berbeda peran di kegiatan berbeda;
 *   2. peran utama = yang paling sering dijalankan;
 *   3. LAWAN perannya benar: kalau pihak ketiga yang jadi pengendali, justru
 *      kita yang berkedudukan sebagai prosesor;
 *   4. pihak ketiga yang belum dipetakan ke RoPA mana pun tetap terkategori,
 *      memakai peran bawaannya — bukan jatuh jadi "tidak diketahui";
 *   5. kegiatan milik tenant lain tidak pernah ikut terhitung.
 */
class VendorPeranPdpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));
    }

    private function vendor(array $override = []): Vendor
    {
        return Vendor::create(array_merge([
            'org_id' => $this->org->id,
            'name' => 'PT Cloud Mitra',
            'assign_group' => '(All Group)',
        ], $override));
    }

    private function ropa(string $nomor, ?string $orgId = null): Ropa
    {
        return Ropa::create([
            'org_id' => $orgId ?? $this->org->id,
            'registration_number' => $nomor,
            'processing_activity' => 'Kegiatan '.$nomor,
            'risk_level' => 'low',
        ]);
    }

    private function tautkan(Ropa $ropa, Vendor $vendor, string $peran, ?string $orgId = null): void
    {
        $ropa->vendors()->attach($vendor->id, [
            'org_id' => $orgId ?? $this->org->id,
            'role' => $peran,
        ]);
    }

    public function test_peran_dibaca_dari_pivot_dan_yang_tersering_jadi_peran_utama(): void
    {
        $vendor = $this->vendor(['type' => Vendor::ROLE_CONTROLLER]); // sengaja beda dari pivot

        $this->tautkan($this->ropa('ROPA-2026-001'), $vendor, Vendor::ROLE_PROCESSOR);
        $this->tautkan($this->ropa('ROPA-2026-002'), $vendor, Vendor::ROLE_PROCESSOR);
        $this->tautkan($this->ropa('ROPA-2026-003'), $vendor, Vendor::ROLE_JOINT_CONTROLLER);

        $row = $this->getJson('/api/vendor-risk')->assertOk()->json('data.0');

        $this->assertSame(2, $row['roles'][Vendor::ROLE_PROCESSOR]);
        $this->assertSame(1, $row['roles'][Vendor::ROLE_JOINT_CONTROLLER]);
        $this->assertSame(3, $row['terpetakan_di_ropa']);
        // Pivot menang atas kolom bawaan.
        $this->assertSame(Vendor::ROLE_PROCESSOR, $row['peran_utama']);
        // Pihak ketiga memproses atas perintah kita → kita pengendalinya.
        $this->assertSame(Vendor::ROLE_CONTROLLER, $row['peran_tenant']);
    }

    public function test_pihak_ketiga_sebagai_pengendali_menjadikan_tenant_prosesor(): void
    {
        $vendor = $this->vendor(['name' => 'PT Biro Kredit']);
        $this->tautkan($this->ropa('ROPA-2026-010'), $vendor, Vendor::ROLE_CONTROLLER);

        $row = $this->getJson('/api/vendor-risk')->assertOk()->json('data.0');

        $this->assertSame(Vendor::ROLE_CONTROLLER, $row['peran_utama']);
        $this->assertSame(Vendor::ROLE_PROCESSOR, $row['peran_tenant'], 'kewajiban kita berbeda saat kita yang jadi prosesor');
        $this->assertSame(Vendor::roleLabel(Vendor::ROLE_PROCESSOR), $row['peran_tenant_label']);
    }

    public function test_subprosesor_menempatkan_tenant_sebagai_prosesor(): void
    {
        $vendor = $this->vendor(['name' => 'PT Sub Olah']);
        $this->tautkan($this->ropa('ROPA-2026-020'), $vendor, Vendor::ROLE_SUB_PROCESSOR);

        $row = $this->getJson('/api/vendor-risk')->assertOk()->json('data.0');

        $this->assertSame(Vendor::ROLE_SUB_PROCESSOR, $row['peran_utama']);
        $this->assertSame(Vendor::ROLE_PROCESSOR, $row['peran_tenant']);
    }

    public function test_belum_tertaut_ropa_memakai_peran_bawaan(): void
    {
        $this->vendor(['name' => 'PT Belum Dipetakan', 'type' => Vendor::ROLE_JOINT_CONTROLLER]);

        $row = $this->getJson('/api/vendor-risk')->assertOk()->json('data.0');

        $this->assertSame([], $row['roles']);
        $this->assertSame(0, $row['terpetakan_di_ropa']);
        // Tetap terkategori, bukan jatuh jadi "belum diketahui".
        $this->assertSame(Vendor::ROLE_JOINT_CONTROLLER, $row['peran_utama']);
        $this->assertSame(Vendor::ROLE_JOINT_CONTROLLER, $row['peran_tenant']);
    }

    public function test_tanpa_peran_bawaan_dihitung_belum_diketahui(): void
    {
        $this->vendor(['name' => 'PT Tanpa Peran']);

        $res = $this->getJson('/api/vendor-risk')->assertOk();

        $res->assertJsonPath('data.0.peran_utama', null);
        $res->assertJsonPath('data.0.peran_tenant', null);
        $res->assertJsonPath('meta.per_peran.belum_diketahui', 1);
    }

    public function test_hitungan_per_peran_untuk_tab_antarmuka(): void
    {
        $a = $this->vendor(['name' => 'PT Prosesor A']);
        $b = $this->vendor(['name' => 'PT Pengendali B']);
        $this->tautkan($this->ropa('ROPA-2026-030'), $a, Vendor::ROLE_PROCESSOR);
        $this->tautkan($this->ropa('ROPA-2026-031'), $b, Vendor::ROLE_CONTROLLER);

        $res = $this->getJson('/api/vendor-risk')->assertOk();

        $res->assertJsonPath('meta.per_peran.'.Vendor::ROLE_PROCESSOR, 1);
        $res->assertJsonPath('meta.per_peran.'.Vendor::ROLE_CONTROLLER, 1);
        $res->assertJsonPath('meta.per_peran.'.Vendor::ROLE_JOINT_CONTROLLER, 0);
    }

    public function test_kegiatan_tenant_lain_tidak_ikut_terhitung(): void
    {
        $vendor = $this->vendor();
        $this->tautkan($this->ropa('ROPA-2026-040'), $vendor, Vendor::ROLE_PROCESSOR);

        // Baris pivot milik organisasi lain untuk pihak ketiga yang sama.
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $ropaTetangga = $this->ropa('ROPA-2026-041', $lain->id);
        $this->tautkan($ropaTetangga, $vendor, Vendor::ROLE_JOINT_CONTROLLER, $lain->id);

        $row = $this->getJson('/api/vendor-risk')->assertOk()->json('data.0');

        $this->assertSame([Vendor::ROLE_PROCESSOR => 1], $row['roles'], 'pivot tenant lain tidak boleh ikut teragregasi');
        $this->assertSame(1, $row['terpetakan_di_ropa']);
    }
}
