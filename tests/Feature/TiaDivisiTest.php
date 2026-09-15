<?php

namespace Tests\Feature;

use App\Models\CrossBorderTransfer;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\TiaAssessment;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batas divisi pada TIA — DITURUNKAN dari induknya.
 *
 * `tia_assessments` tidak punya kolom penugasan sendiri. Divisinya datang dari
 * RoPA, pihak ketiga, atau transfer lintas negara yang ditautkannya — dan yang
 * terakhir itu keterlihatannya SENDIRI juga turunan, jadi klausanya bersarang.
 * Aturannya SALAH SATU: cukup satu induk terlihat.
 */
class TiaDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['tia']): User
    {
        $departemen = $divisi ? Department::create([
            'org_id' => $this->org->id,
            'name' => $divisi,
        ]) : null;

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => $namaPeran,
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    private function pihakKetiga(string $nama, ?string $divisi): Vendor
    {
        return Vendor::create(['org_id' => $this->org->id, 'name' => $nama, 'assign_group' => $divisi]);
    }

    private function ropa(string $kegiatan, ?string $divisi): Ropa
    {
        return Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -6),
            'processing_activity' => $kegiatan,
            'assign_group' => $divisi,
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $tautan */
    private function transfer(string $negara, array $tautan = []): CrossBorderTransfer
    {
        return CrossBorderTransfer::create(array_merge([
            'org_id' => $this->org->id,
            'destination_country' => $negara,
            'destination_entity' => "Penerima {$negara}",
            'transfer_purpose' => 'Hosting',
            'legal_basis' => 'adequacy',
        ], $tautan));
    }

    /** @param array<string, mixed> $tautan */
    private function tia(string $judul, array $tautan = []): TiaAssessment
    {
        return TiaAssessment::create(array_merge([
            'org_id' => $this->org->id,
            'tia_code' => 'TIA-'.substr(uniqid(), -8),
            'title' => $judul,
            'status' => TiaAssessment::STATUS_DRAFT,
        ], $tautan));
    }

    /** @return array<int, string> */
    private function judulTerlihat(): array
    {
        return array_column(
            $this->getJson('/api/tia?per_page=200')->assertOk()->json('data.data'),
            'title',
        );
    }

    #[Test]
    public function staf_hanya_melihat_tia_lewat_ropa_divisinya(): void
    {
        $this->tia('TIA Rekrutmen', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->tia('TIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('TIA Rekrutmen', $judul);
        $this->assertNotContains('TIA Penagihan', $judul);
    }

    #[Test]
    public function staf_melihat_tia_lewat_pihak_ketiga_divisinya(): void
    {
        $this->tia('TIA Talenta', ['linked_vendor_id' => $this->pihakKetiga('PT Talenta', 'HR')->id]);
        $this->tia('TIA Bayar', ['linked_vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('TIA Talenta', $judul);
        $this->assertNotContains('TIA Bayar', $judul);
    }

    #[Test]
    public function keterlihatan_transfer_ikut_menurun_dua_lapis(): void
    {
        // TIA → transfer → pihak ketiga. Divisinya hanya bisa ditemukan kalau
        // klausa transfer benar-benar dipinjam utuh, bukan berhenti satu lapis.
        $milikHr = $this->transfer('Singapura', ['vendor_id' => $this->pihakKetiga('PT Talenta', 'HR')->id]);
        $milikKeuangan = $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        $this->tia('TIA Singapura', ['linked_cross_border_id' => $milikHr->id]);
        $this->tia('TIA Jepang', ['linked_cross_border_id' => $milikKeuangan->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('TIA Singapura', $judul);
        $this->assertNotContains('TIA Jepang', $judul);
    }

    #[Test]
    public function cukup_salah_satu_induk_terlihat_bukan_semuanya(): void
    {
        // RoPA-nya milik HR, pihak ketiganya milik Keuangan. Keduanya berhak.
        $this->tia('TIA Campuran', [
            'linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id,
            'linked_vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id,
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('TIA Campuran', $this->judulTerlihat());
    }

    #[Test]
    public function pembuat_tetap_melihat_hasil_kerjanya_sendiri(): void
    {
        $staf = $this->pengguna('maker', 'staff', 'HR');
        $this->tia('TIA Tanpa Tautan', [
            'linked_vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id,
            'created_by' => $staf->id,
        ]);

        Sanctum::actingAs($staf);
        $this->assertContains('TIA Tanpa Tautan', $this->judulTerlihat());
    }

    #[Test]
    public function tia_tanpa_induk_terlihat_semua_divisi(): void
    {
        $this->tia('TIA Lepas');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('TIA Lepas', $this->judulTerlihat());
    }

    #[Test]
    public function induk_hilang_permanen_membuat_tia_kembali_tanpa_divisi(): void
    {
        $pihak = $this->pihakKetiga('PT Bayar', 'Keuangan');
        $this->tia('TIA Yatim', ['linked_vendor_id' => $pihak->id]);

        $pihak->forceDelete();

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('TIA Yatim', $this->judulTerlihat());
    }

    #[Test]
    public function dpo_melihat_seluruh_tenant(): void
    {
        $this->tia('TIA Rekrutmen', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->tia('TIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('TIA Rekrutmen', $judul);
        $this->assertContains('TIA Penagihan', $judul);
    }

    #[Test]
    public function detail_tia_divisi_lain_tidak_bisa_dibuka(): void
    {
        $r = $this->tia('TIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->getJson("/api/tia/{$r->id}")->assertNotFound();
    }

    #[Test]
    public function tia_divisi_lain_tidak_bisa_diubah(): void
    {
        $r = $this->tia('TIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->putJson("/api/tia/{$r->id}", ['title' => 'Disusupi'])->assertNotFound();

        $this->assertSame('TIA Penagihan', $r->fresh()->title);
    }

    #[Test]
    public function tia_divisi_lain_tidak_bisa_dihapus(): void
    {
        $r = $this->tia('TIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->deleteJson("/api/tia/{$r->id}")->assertNotFound();

        $this->assertNull($r->fresh()->deleted_at);
    }

    #[Test]
    public function tia_tidak_bisa_dibuat_dari_ropa_divisi_lain(): void
    {
        // Cuplikan isi RoPA ikut tersalin ke `wizard_data`, jadi ini bukan
        // sekadar tautan — datanya benar-benar berpindah tangan.
        $lain = $this->ropa('Penagihan', 'Keuangan');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->postJson("/api/tia/from-ropa/{$lain->id}")->assertNotFound();

        $this->assertDatabaseCount('tia_assessments', 0);
    }

    #[Test]
    public function tia_tidak_bisa_dibuat_dari_pihak_ketiga_divisi_lain(): void
    {
        $lain = $this->pihakKetiga('PT Bayar', 'Keuangan');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->postJson("/api/tia/from-vendor/{$lain->id}")->assertNotFound();

        $this->assertDatabaseCount('tia_assessments', 0);
    }

    #[Test]
    public function tia_tidak_bisa_dibuat_dari_transfer_divisi_lain(): void
    {
        $lain = $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->postJson("/api/tia/from-cross-border/{$lain->id}")->assertNotFound();

        $this->assertDatabaseCount('tia_assessments', 0);
    }

    #[Test]
    public function tia_tetap_bisa_dibuat_dari_sumber_divisi_sendiri(): void
    {
        $milikHr = $this->ropa('Rekrutmen', 'HR');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->postJson("/api/tia/from-ropa/{$milikHr->id}")->assertSuccessful();

        $this->assertDatabaseCount('tia_assessments', 1);
    }
}
