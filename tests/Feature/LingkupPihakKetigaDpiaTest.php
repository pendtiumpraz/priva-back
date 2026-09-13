<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pihak ketiga dalam lingkup sebuah DPIA (pivot `dpia_vendor`).
 *
 * Yang paling penting di sini bukan penyimpanannya, melainkan penjagaannya:
 * pivot ditulis lewat query builder, yang TIDAK melewati scope Eloquent mana
 * pun. Kepemilikan karena itu harus diperiksa sendiri, dan uji inilah yang
 * memastikan pemeriksaan itu benar-benar ada.
 */
class LingkupPihakKetigaDpiaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $orgLain;

    private User $admin;

    private Dpia $dpia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'Bank Uji']);
        $this->orgLain = Organization::factory()->create(['name' => 'Bank Lain']);

        $peran = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['dpia:read', 'dpia:write'],
        ]);
        $this->admin = User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $peran->id]);

        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Pemrosesan Gaji',
        ]);
        $this->dpia = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id,
            'registration_number' => 'DPIA-2026-001', 'status' => 'draft',
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function pihak(Organization $org, string $nama): Vendor
    {
        return Vendor::create(['org_id' => $org->id, 'name' => $nama]);
    }

    public function test_menyimpan_dan_membaca_pihak_ketiga_dalam_lingkup(): void
    {
        $a = $this->pihak($this->org, 'PT Awan');
        $b = $this->pihak($this->org, 'PT Kurir');

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [
                ['id' => $a->id, 'role' => Vendor::ROLE_PROCESSOR],
                ['id' => $b->id, 'role' => Vendor::ROLE_SUB_PROCESSOR, 'notes' => 'hanya pengiriman'],
            ],
        ])->assertOk();

        $res = $this->getJson("/api/dpia/{$this->dpia->id}/pihak-ketiga")->assertOk();

        $this->assertCount(2, $res->json('data'));
        $kurir = collect($res->json('data'))->firstWhere('id', $b->id);
        $this->assertSame(Vendor::ROLE_SUB_PROCESSOR, $kurir['role']);
        $this->assertSame('hanya pengiriman', $kurir['notes']);
    }

    public function test_menyimpan_mengganti_seluruh_daftar_bukan_menambah(): void
    {
        $a = $this->pihak($this->org, 'PT Awan');
        $b = $this->pihak($this->org, 'PT Kurir');

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $a->id], ['id' => $b->id]],
        ])->assertOk();

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $b->id]],
        ])->assertOk();

        $sisa = DB::table('dpia_vendor')->where('dpia_id', $this->dpia->id)->pluck('vendor_id')->all();
        $this->assertSame([$b->id], $sisa);
    }

    public function test_daftar_kosong_melepas_semua_tautan(): void
    {
        $a = $this->pihak($this->org, 'PT Awan');
        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $a->id]],
        ])->assertOk();

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", ['third_parties' => []])->assertOk();

        $this->assertSame(0, DB::table('dpia_vendor')->where('dpia_id', $this->dpia->id)->count());
    }

    /**
     * Penjagaan yang paling menentukan: pivot ditulis lewat query builder, jadi
     * scope global `org` tidak ikut campur sama sekali di sana.
     */
    public function test_pihak_ketiga_milik_organisasi_lain_ditolak(): void
    {
        $asing = $this->pihak($this->orgLain, 'PT Tetangga');

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $asing->id]],
        ])->assertStatus(422)->assertJsonValidationErrors(['third_parties']);

        $this->assertSame(0, DB::table('dpia_vendor')->count());
    }

    public function test_satu_id_asing_membatalkan_seluruh_kiriman(): void
    {
        $milik = $this->pihak($this->org, 'PT Awan');
        $asing = $this->pihak($this->orgLain, 'PT Tetangga');

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $milik->id], ['id' => $asing->id]],
        ])->assertStatus(422);

        // Tidak separuh tersimpan: penolakan terjadi SEBELUM transaksi menulis.
        $this->assertSame(0, DB::table('dpia_vendor')->count());
    }

    public function test_dpia_organisasi_lain_tidak_bisa_disentuh(): void
    {
        $ropaAsing = Ropa::create([
            'org_id' => $this->orgLain->id,
            'registration_number' => 'ROPA-2026-900',
            'processing_activity' => 'Milik tetangga',
        ]);
        $dpiaAsing = Dpia::create([
            'org_id' => $this->orgLain->id, 'ropa_id' => $ropaAsing->id,
            'registration_number' => 'DPIA-2026-900', 'status' => 'draft',
        ]);

        $this->getJson("/api/dpia/{$dpiaAsing->id}/pihak-ketiga")->assertNotFound();
        $this->putJson("/api/dpia/{$dpiaAsing->id}/pihak-ketiga", ['third_parties' => []])->assertNotFound();
    }

    public function test_peran_di_luar_kosakata_ditolak(): void
    {
        $a = $this->pihak($this->org, 'PT Awan');

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", [
            'third_parties' => [['id' => $a->id, 'role' => 'pengawas']],
        ])->assertStatus(422)->assertJsonValidationErrors(['third_parties.0.role']);
    }

    public function test_peran_tanpa_izin_tulis_tidak_bisa_menyimpan(): void
    {
        $biasa = User::factory()->create(['org_id' => $this->org->id, 'role' => 'staff']);
        Sanctum::actingAs($biasa);

        $this->putJson("/api/dpia/{$this->dpia->id}/pihak-ketiga", ['third_parties' => []])
            ->assertForbidden();
    }
}
