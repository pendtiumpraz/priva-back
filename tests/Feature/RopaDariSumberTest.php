<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\CrossBorderTransfer;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRopa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Buat RoPA" dari modul yang belum tertaut.
 *
 * Yang dikunci di sini:
 *   1. isi RoPA DITURUNKAN dari record sumber milik kita sendiri — klien cuma
 *      mengirim sumber + id, tidak pernah isinya;
 *   2. yang dibuat selalu DRAF, karena tujuan & dasar hukumnya belum tentu
 *      lengkap dan RoPA yang belum dinilai tidak boleh masuk register sebagai
 *      record jadi;
 *   3. risikonya TIDAK ditebak — belum ada yang menilainya;
 *   4. sumber yang sudah tertaut ditolak, supaya satu sumber tidak melahirkan
 *      RoPA kembar setiap tombolnya ditekan;
 *   5. sumber milik tenant lain tidak bisa disisipkan lewat badan permintaan.
 */
class RopaDariSumberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $peran = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        $this->user = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $peran->id,
        ]);
        Sanctum::actingAs($this->user);
    }

    private function vendorRopa(?string $orgId = null): VendorRopa
    {
        $orgId ??= $this->org->id;
        $vendor = Vendor::create([
            'org_id' => $orgId,
            'name' => 'PT Awan Data',
            'type' => 'processor',
            'country' => 'Singapura',
        ]);

        return VendorRopa::create([
            'org_id' => $orgId,
            'vendor_id' => $vendor->id,
            'processing_activity' => 'Hosting basis data pelanggan',
            'purpose' => 'Menyimpan dan mencadangkan data pelanggan',
            'legal_basis' => 'Kontrak',
            'role' => 'processor',
            'data_categories' => ['Nama', 'Email', 'Nomor telepon'],
            'data_subjects' => ['Pelanggan'],
            'retention_period' => '5 tahun',
            'security_measures' => ['Enkripsi at-rest', 'Kontrol akses'],
        ]);
    }

    public function test_ropa_diturunkan_dari_laporan_pihak_ketiga(): void
    {
        $vr = $this->vendorRopa();

        $res = $this->postJson('/api/ropa/dari-sumber', [
            'sumber' => 'vendor_ropa',
            'id' => $vr->id,
        ])->assertStatus(201);

        $ropa = Ropa::find($res->json('data.id'));

        $this->assertSame('Hosting basis data pelanggan', $ropa->processing_activity);
        $this->assertSame('Menyimpan dan mencadangkan data pelanggan', $ropa->purpose);
        $this->assertSame('Kontrak', $ropa->legal_basis);
        $this->assertSame(['Nama', 'Email', 'Nomor telepon'], $ropa->data_categories);
        $this->assertSame('5 tahun', $ropa->retention_period);

        // Pihak ketiganya sendiri adalah penerima data — kalau tidak dituliskan,
        // RoPA-nya justru menghilangkan fakta yang membuatnya ada.
        $this->assertSame(['PT Awan Data'], $ropa->recipients);

        // Bentuk kedua kolom ini berlawanan antara sumber dan tujuan:
        // `vendor_ropas.security_measures` larik, `ropas.security_measures` teks.
        $this->assertSame('Enkripsi at-rest, Kontrol akses', $ropa->security_measures);

        // Yang menandai record ini belum jadi adalah STATUSNYA, bukan risikonya.
        // `ropas.risk_level` NOT NULL dengan default 'low', jadi setiap RoPA baru
        // — lewat jalur mana pun — terbaca "rendah" sebelum ada yang menilainya.
        // Dikunci di sini supaya kalau default itu suatu saat diperbaiki, tes ini
        // ikut menunjukkan jalur mana saja yang terpengaruh.
        $this->assertSame('draft', $ropa->status);
        $this->assertFalse((bool) $ropa->risk_level_locked, 'risiko harus bisa dihitung ulang saat wizard dilengkapi');

        // Tautannya langsung terpasang — tombolnya tidak boleh muncul lagi.
        $this->assertTrue($vr->fresh()->ropas()->where('ropas.id', $ropa->id)->exists());
    }

    public function test_klien_tidak_bisa_menentukan_isi_ropa(): void
    {
        $vr = $this->vendorRopa();

        $res = $this->postJson('/api/ropa/dari-sumber', [
            'sumber' => 'vendor_ropa',
            'id' => $vr->id,
            // Semua ini diabaikan: isinya dibaca ulang dari record sumber.
            'processing_activity' => 'Dikarang dari luar',
            'purpose' => 'Dikarang dari luar',
            'status' => 'approved',
            'recipients' => ['Dikarang dari luar'],
        ])->assertStatus(201);

        $ropa = Ropa::find($res->json('data.id'));

        $this->assertSame('Hosting basis data pelanggan', $ropa->processing_activity);
        $this->assertSame('Menyimpan dan mencadangkan data pelanggan', $ropa->purpose);
        $this->assertSame(['PT Awan Data'], $ropa->recipients);
        // Yang paling penting: status tidak bisa dinaikkan dari luar menjadi
        // record jadi tanpa melewati alur peninjauan.
        $this->assertSame('draft', $ropa->status);
    }

    public function test_sumber_yang_sudah_tertaut_ditolak(): void
    {
        $vr = $this->vendorRopa();

        $this->postJson('/api/ropa/dari-sumber', ['sumber' => 'vendor_ropa', 'id' => $vr->id])
            ->assertStatus(201);

        // Tombolnya memang disembunyikan saat sudah tertaut, tetapi server tidak
        // boleh bergantung pada itu: satu sumber tidak boleh melahirkan RoPA
        // kembar hanya karena permintaannya terkirim dua kali.
        $this->postJson('/api/ropa/dari-sumber', ['sumber' => 'vendor_ropa', 'id' => $vr->id])
            ->assertStatus(409);

        $this->assertSame(1, Ropa::where('org_id', $this->org->id)->count());
    }

    public function test_ropa_diturunkan_dari_titik_pengumpulan_persetujuan(): void
    {
        $titik = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-777',
            'name' => 'Banner Situs Utama',
            'kind' => ConsentCollectionPoint::KIND_APP,
            'domain' => 'privasimu.id',
        ]);
        ConsentItem::create([
            'org_id' => $this->org->id,
            'collection_point_id' => $titik->id,
            'title' => 'Pemasaran langsung',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/ropa/dari-sumber', [
            'sumber' => 'consent',
            'id' => $titik->id,
        ])->assertStatus(201);

        $ropa = Ropa::find($res->json('data.id'));

        // Dasar hukumnya bukan tebakan: titik ini memang ada untuk mengumpulkan
        // persetujuan.
        $this->assertSame('Persetujuan', $ropa->legal_basis);
        $this->assertStringContainsString('Banner Situs Utama', $ropa->processing_activity);
        $this->assertStringContainsString('Pemasaran langsung', (string) $ropa->purpose);
        $this->assertSame('draft', $ropa->status);

        $this->assertSame($ropa->id, $titik->fresh()->settings['linked_ropa_id']);
    }

    public function test_ropa_diturunkan_dari_transfer_lintas_negara(): void
    {
        $cb = CrossBorderTransfer::create([
            'org_id' => $this->org->id,
            'destination_country' => 'Singapura',
            'destination_entity' => 'Mailchimp Pte Ltd',
            'transfer_purpose' => 'Pengiriman email pemasaran',
            'legal_basis' => 'Persetujuan',
            'data_categories' => ['Email', 'Nama'],
            'safeguards' => ['SCC', 'Enkripsi in-transit'],
            'retention_period_days' => 365,
        ]);

        $res = $this->postJson('/api/ropa/dari-sumber', [
            'sumber' => 'cross_border',
            'id' => $cb->id,
        ])->assertStatus(201);

        $ropa = Ropa::find($res->json('data.id'));

        // "Ke mana" adalah bagian dari identitas kegiatan transfer, jadi negara
        // tujuannya masuk ke nama kegiatan — bukan hanya deskripsi.
        $this->assertStringContainsString('Mailchimp Pte Ltd', $ropa->processing_activity);
        $this->assertStringContainsString('Singapura', $ropa->processing_activity);

        $this->assertSame('Pengiriman email pemasaran', $ropa->purpose);
        $this->assertSame('Persetujuan', $ropa->legal_basis);
        $this->assertSame(['Email', 'Nama'], $ropa->data_categories);
        $this->assertSame(['Mailchimp Pte Ltd'], $ropa->recipients);
        $this->assertSame('SCC, Enkripsi in-transit', $ropa->security_measures);

        // Satuannya WAJIB ikut: "365" tanpa satuan bisa terbaca bulan atau tahun.
        $this->assertSame('365 hari', $ropa->retention_period);

        $this->assertSame('draft', $ropa->status);
        $this->assertSame($ropa->id, $cb->fresh()->linked_ropa_id);
    }

    public function test_transfer_yang_sudah_tertaut_ditolak(): void
    {
        $cb = CrossBorderTransfer::create([
            'org_id' => $this->org->id,
            'destination_country' => 'Singapura',
            'destination_entity' => 'Mailchimp Pte Ltd',
            'transfer_purpose' => 'Pengiriman email pemasaran',
        ]);

        $this->postJson('/api/ropa/dari-sumber', ['sumber' => 'cross_border', 'id' => $cb->id])
            ->assertStatus(201);
        $this->postJson('/api/ropa/dari-sumber', ['sumber' => 'cross_border', 'id' => $cb->id])
            ->assertStatus(409);

        $this->assertSame(1, Ropa::where('org_id', $this->org->id)->count());
    }

    public function test_sumber_tenant_lain_tidak_bisa_disisipkan(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Sebelah']);
        $vr = $this->vendorRopa($lain->id);

        $this->postJson('/api/ropa/dari-sumber', ['sumber' => 'vendor_ropa', 'id' => $vr->id])
            ->assertStatus(404);

        $this->assertSame(0, Ropa::where('org_id', $this->org->id)->count());
    }

    public function test_sumber_yang_tidak_dikenal_ditolak(): void
    {
        $this->postJson('/api/ropa/dari-sumber', [
            'sumber' => 'dsr',
            'id' => '00000000-0000-4000-8000-000000000000',
        ])->assertStatus(422);
    }
}
