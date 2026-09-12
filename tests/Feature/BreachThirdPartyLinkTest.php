<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorIncident;
use App\Models\VendorRopa;
use App\Services\ConnectionMap\ConnectionMapScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Insiden ↔ pihak ketiga.
 *
 * Janji yang dijaga di sini:
 *   1. pihak ketiga yang DIPASTIKAN terlibat tersimpan di insiden;
 *   2. dugaan ditelusuri dari RoPA terdampak — lewat peran di pivot maupun
 *      lewat RoPA yang diisi pihak ketiga sendiri — dan selalu disajikan
 *      TERPISAH dari yang dipastikan;
 *   3. satu kejadian bisa dibuka sebagai kasus di register TPRM tanpa menjadi
 *      dua catatan yang tak berhubungan;
 *   4. tidak ada catatan tenant lain yang ikut muncul.
 */
class BreachThirdPartyLinkTest extends TestCase
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
            'permissions' => ['breach:read', 'breach:write', 'ropa:read', 'ropa:write', 'vendor_risk:read', 'vendor_risk:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));
    }

    private function ropa(string $activity): Ropa
    {
        return Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -4),
            'processing_activity' => $activity,
        ]);
    }

    private function breach(array $extra = []): BreachIncident
    {
        return BreachIncident::create(array_merge([
            'org_id' => $this->org->id,
            'incident_code' => 'BRC-2026-'.substr(uniqid(), -4),
            'title' => 'Kebocoran basis data nasabah',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now(),
        ], $extra));
    }

    public function test_pihak_ketiga_yang_dipastikan_tersimpan_di_insiden(): void
    {
        $vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
        $breach = $this->breach();

        $this->putJson("/api/m/breach/{$breach->id}", ['linked_vendor_ids' => [$vendor->id]])->assertSuccessful();

        $this->assertSame([$vendor->id], $breach->fresh()->linked_vendor_ids);
        $this->assertSame('PT Cloud Mitra', $breach->fresh()->linked_third_parties[0]['name']);
    }

    public function test_dugaan_ditelusuri_dari_ropa_terdampak(): void
    {
        $ropa = $this->ropa('Onboarding Nasabah');
        $prosesor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
        $bersama = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Bank Rekan']);
        $ropa->vendors()->attach($prosesor->id, ['org_id' => $this->org->id, 'role' => Vendor::ROLE_PROCESSOR]);
        $ropa->vendors()->attach($bersama->id, ['org_id' => $this->org->id, 'role' => Vendor::ROLE_JOINT_CONTROLLER]);

        $breach = $this->breach(['linked_ropa_ids' => [$ropa->id], 'linked_ropa_id' => $ropa->id]);

        $data = $this->getJson("/api/breach/{$breach->id}/pihak-ketiga")->assertOk()->json('data');

        $this->assertSame(1, $data['ropa_terdampak']);
        $this->assertCount(2, $data['dugaan']);
        $this->assertEmpty($data['dipastikan']);

        $byName = collect($data['dugaan'])->keyBy('name');
        $this->assertStringContainsString('Prosesor', $byName['PT Cloud Mitra']['alasan'][0]['teks']);
        $this->assertStringContainsString('Pengendali Bersama', $byName['PT Bank Rekan']['alasan'][0]['teks']);
        $this->assertFalse($byName['PT Cloud Mitra']['sudah_ditautkan']);
    }

    public function test_ropa_yang_diisi_pihak_ketiga_ikut_menjadi_dugaan(): void
    {
        $ropa = $this->ropa('Penggajian');
        $vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Payroll Mitra']);

        $vendorRopa = VendorRopa::create([
            'org_id' => $this->org->id,
            'vendor_id' => $vendor->id,
            'processing_activity' => 'Pemrosesan slip gaji',
            'status' => VendorRopa::STATUS_SUBMITTED,
        ]);
        $vendorRopa->ropas()->attach($ropa->id, ['org_id' => $this->org->id]);

        $breach = $this->breach(['linked_ropa_ids' => [$ropa->id]]);

        $data = $this->getJson("/api/breach/{$breach->id}/pihak-ketiga")->assertOk()->json('data');

        $this->assertCount(1, $data['dugaan']);
        $this->assertSame('PT Payroll Mitra', $data['dugaan'][0]['name']);
        $this->assertSame('vendor_ropa', $data['dugaan'][0]['alasan'][0]['jenis']);
    }

    public function test_membuka_kasus_di_register_tprm_menautkan_keduanya(): void
    {
        $vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
        $breach = $this->breach();

        $this->postJson("/api/breach/{$breach->id}/pihak-ketiga/insiden-tprm", ['vendor_id' => $vendor->id])
            ->assertCreated();

        $incident = VendorIncident::first();
        $this->assertSame($breach->id, $incident->linked_breach_id);
        $this->assertSame(VendorIncident::KIND_DATA_BREACH, $incident->kind);
        // Sudah dibuka kasusnya → keterlibatannya bukan dugaan lagi.
        $this->assertSame([$vendor->id], $breach->fresh()->linked_vendor_ids);

        // Tidak menggandakan kasus untuk kejadian yang sama.
        $this->postJson("/api/breach/{$breach->id}/pihak-ketiga/insiden-tprm", ['vendor_id' => $vendor->id])->assertOk();
        $this->assertSame(1, VendorIncident::count());
    }

    public function test_peta_koneksi_menggambar_tautan_insiden_ke_pihak_ketiga(): void
    {
        $vendor = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
        $breach = $this->breach(['linked_vendor_ids' => [$vendor->id]]);

        $graph = app(ConnectionMapScanner::class)->scan($this->org->fresh());

        $edge = collect($graph['edges'])
            ->first(fn ($e) => $e['from'] === 'breach:'.$breach->id && $e['to'] === 'thirdparty:'.$vendor->id);

        $this->assertNotNull($edge, 'insiden yang menautkan pihak ketiga harus tergambar di peta koneksi');
        $this->assertSame('melibatkan pihak ketiga', $edge['label']);
    }

    public function test_insiden_tenant_lain_tidak_bisa_dibuka(): void
    {
        $tetangga = Organization::factory()->create(['name' => 'PT Tetangga']);
        $breachTetangga = BreachIncident::create([
            'org_id' => $tetangga->id,
            'incident_code' => 'BRC-2026-9999',
            'title' => 'Insiden tenant lain',
            'severity' => 'low',
            'status' => 'detected',
            'detected_at' => now(),
        ]);

        $this->getJson("/api/breach/{$breachTetangga->id}/pihak-ketiga")->assertStatus(404);
    }
}
