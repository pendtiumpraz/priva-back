<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ConnectionMap\ConnectionMapScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tautan RoPA ↔ pihak ketiga beserta PERAN-nya (pivot `ropa_vendor`).
 *
 * Janji yang dijaga di sini:
 *   1. wizard menulis tautan → pivot ikut tersinkron, lengkap dengan peran;
 *   2. daftar UUID polos (wizard lama, impor CSV, isian AI) tetap tertaut
 *      sebagai Prosesor — bagian wizard itu memang "pihak yang memproses";
 *   3. tautan yang dihapus dari wizard ikut lepas dari pivot;
 *   4. pihak ketiga milik tenant lain tidak pernah tertaut.
 */
class RopaVendorLinkTest extends TestCase
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
            'permissions' => ['ropa:read', 'ropa:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));
    }

    private function vendor(string $name, ?Organization $org = null): Vendor
    {
        return Vendor::create(['org_id' => ($org ?? $this->org)->id, 'name' => $name]);
    }

    /** @param array<string, mixed> $penggunaan */
    private function payload(string $activity, array $penggunaan): array
    {
        return [
            'processing_activity' => $activity,
            'wizard_data' => ['penggunaan_penyimpanan' => $penggunaan],
        ];
    }

    private function createRopa(string $activity, array $penggunaan): string
    {
        $res = $this->postJson('/api/m/ropa', $this->payload($activity, $penggunaan))->assertSuccessful();

        return $res->json('data.id') ?? $res->json('id');
    }

    /** @return array<string, object> baris pivot, dikunci vendor_id */
    private function links(string $ropaId): array
    {
        return DB::table('ropa_vendor')->where('ropa_id', $ropaId)->get()->keyBy('vendor_id')->all();
    }

    public function test_wizard_menyimpan_peran_tiap_pihak_ketiga(): void
    {
        $prosesor = $this->vendor('PT Cloud Mitra');
        $bersama = $this->vendor('PT Bank Rekan');

        $ropaId = $this->createRopa('Onboarding Nasabah', ['vendor_links' => [
            ['id' => $prosesor->id, 'role' => 'processor', 'purpose' => 'Hosting basis data nasabah', 'data_shared' => ['Nama', 'NIK']],
            ['id' => $bersama->id, 'role' => 'joint_controller', 'contract_ref' => 'PKS-2026-014'],
        ]]);

        $links = $this->links($ropaId);
        $this->assertCount(2, $links);
        $this->assertSame('processor', $links[$prosesor->id]->role);
        $this->assertSame('Hosting basis data nasabah', $links[$prosesor->id]->purpose);
        $this->assertSame(['Nama', 'NIK'], json_decode((string) $links[$prosesor->id]->data_shared, true));
        $this->assertSame($this->org->id, $links[$prosesor->id]->org_id);
        $this->assertSame('joint_controller', $links[$bersama->id]->role);
        $this->assertSame('PKS-2026-014', $links[$bersama->id]->contract_ref);

        // Dibaca lewat relasi, data_shared kembali sebagai array (cast pivot).
        $ropa = Ropa::findOrFail($ropaId);
        $this->assertSame(['Nama', 'NIK'], $ropa->vendors()->findOrFail($prosesor->id)->pivot->data_shared);
    }

    public function test_daftar_uuid_polos_tertaut_sebagai_prosesor(): void
    {
        $vendor = $this->vendor('PT Cloud Mitra');

        $ropaId = $this->createRopa('Penggajian', ['vendor_ids' => [$vendor->id]]);

        $links = $this->links($ropaId);
        $this->assertCount(1, $links);
        $this->assertSame('processor', $links[$vendor->id]->role);
    }

    public function test_tautan_yang_dihapus_dari_wizard_ikut_lepas(): void
    {
        $tetap = $this->vendor('PT Cloud Mitra');
        $dilepas = $this->vendor('PT Kurir Cepat');

        $ropaId = $this->createRopa('Pengiriman Dokumen', ['vendor_links' => [
            ['id' => $tetap->id, 'role' => 'processor'],
            ['id' => $dilepas->id, 'role' => 'sub_processor'],
        ]]);
        $this->assertCount(2, $this->links($ropaId));

        $this->putJson("/api/m/ropa/{$ropaId}", $this->payload('Pengiriman Dokumen', ['vendor_links' => [
            ['id' => $tetap->id, 'role' => 'controller'],
        ]]))->assertSuccessful();

        $links = $this->links($ropaId);
        $this->assertCount(1, $links);
        $this->assertArrayNotHasKey($dilepas->id, $links);
        $this->assertSame('controller', $links[$tetap->id]->role, 'peran ikut diperbarui saat wizard diubah');
    }

    public function test_pihak_ketiga_tenant_lain_tidak_pernah_tertaut(): void
    {
        $tetangga = Organization::factory()->create(['name' => 'PT Tetangga']);
        $milikTetangga = $this->vendor('PT Cloud Tetangga', $tetangga);
        $milikSendiri = $this->vendor('PT Cloud Mitra');

        $ropaId = $this->createRopa('Analitik Pemasaran', ['vendor_links' => [
            ['id' => $milikSendiri->id, 'role' => 'processor'],
            ['id' => $milikTetangga->id, 'role' => 'processor'],
        ]]);

        $links = $this->links($ropaId);
        $this->assertCount(1, $links);
        $this->assertArrayHasKey($milikSendiri->id, $links);
        $this->assertArrayNotHasKey($milikTetangga->id, $links);
    }

    public function test_peta_koneksi_membedakan_tepi_menurut_peran(): void
    {
        $prosesor = $this->vendor('PT Cloud Mitra');
        $bersama = $this->vendor('PT Bank Rekan');

        $ropaId = $this->createRopa('Program Loyalitas', ['vendor_links' => [
            ['id' => $prosesor->id, 'role' => 'processor'],
            ['id' => $bersama->id, 'role' => 'joint_controller'],
        ]]);

        $graph = app(ConnectionMapScanner::class)->scan($this->org->fresh());
        $edge = fn (string $vendorId) => collect($graph['edges'])
            ->first(fn ($e) => $e['from'] === 'ropa:'.$ropaId && $e['to'] === 'thirdparty:'.$vendorId);

        $this->assertSame('processed_by', $edge($prosesor->id)['relation']);
        $this->assertSame('joint_controller_with', $edge($bersama->id)['relation']);
        $this->assertSame('pengendali bersama', $edge($bersama->id)['label']);
    }
}
