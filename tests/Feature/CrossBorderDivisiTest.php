<?php

namespace Tests\Feature;

use App\Models\CrossBorderTransfer;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Support\AssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batas divisi pada Transfer Lintas Negara — DITURUNKAN dari induknya.
 *
 * `cross_border_transfers` tidak punya kolom penugasan sendiri dan bahkan tidak
 * punya `created_by`; divisinya datang dari pihak ketiga penerima dan RoPA yang
 * ditautkannya. Aturannya SALAH SATU: cukup satu induk terlihat.
 *
 * Yang diuji bukan aturan divisinya (itu milik AssignmentScope, sudah diuji
 * tersendiri) melainkan bahwa pewarisannya terpasang di tiap jalur, bahwa
 * "salah satu" benar-benar berarti salah satu, dan bahwa transfer tanpa induk
 * tidak ikut tersapu.
 */
class CrossBorderDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['cross_border']): User
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
        return Vendor::create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'assign_group' => $divisi,
        ]);
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

    /** @return array<int, string> */
    private function negaraTerlihat(): array
    {
        return array_column($this->getJson('/api/cross-border?per_page=200')->assertOk()->json('data'), 'destination_country');
    }

    #[Test]
    public function staf_hanya_melihat_transfer_pihak_ketiga_divisinya(): void
    {
        $this->transfer('Singapura', ['vendor_id' => $this->pihakKetiga('PT Talenta', 'HR')->id]);
        $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $negara = $this->negaraTerlihat();
        $this->assertContains('Singapura', $negara);
        $this->assertNotContains('Jepang', $negara);
    }

    #[Test]
    public function staf_melihat_transfer_lewat_ropa_divisinya(): void
    {
        $this->transfer('Singapura', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->transfer('Jepang', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $negara = $this->negaraTerlihat();
        $this->assertContains('Singapura', $negara);
        $this->assertNotContains('Jepang', $negara);
    }

    #[Test]
    public function cukup_salah_satu_induk_terlihat_bukan_semuanya(): void
    {
        // Pihak ketiganya milik Keuangan, RoPA-nya milik HR. Keduanya berhak.
        $this->transfer('Singapura', [
            'vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id,
            'linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id,
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Singapura', $this->negaraTerlihat());
    }

    #[Test]
    public function ropa_tambahan_di_larik_json_ikut_memberi_akses(): void
    {
        // `linked_ropa_id` disinkronkan ke ELEMEN PERTAMA saja oleh
        // CrossBorderController. Tanpa klausa untuk lariknya, divisi yang
        // tercantum di urutan kedua tidak akan pernah bisa melihatnya.
        $keuangan = $this->ropa('Penagihan', 'Keuangan');
        $hr = $this->ropa('Rekrutmen', 'HR');

        $this->transfer('Singapura', [
            'linked_ropa_id' => $keuangan->id,
            'linked_ropa_ids' => [$keuangan->id, $hr->id],
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Singapura', $this->negaraTerlihat());
    }

    #[Test]
    public function larik_json_tidak_memberi_akses_ke_divisi_yang_tidak_tercantum(): void
    {
        $keuangan = $this->ropa('Penagihan', 'Keuangan');
        $legal = $this->ropa('Kontrak', 'Legal');

        $this->transfer('Jepang', [
            'linked_ropa_id' => $keuangan->id,
            'linked_ropa_ids' => [$keuangan->id, $legal->id],
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertNotContains('Jepang', $this->negaraTerlihat());
    }

    #[Test]
    public function transfer_tanpa_induk_terlihat_semua_divisi(): void
    {
        // Entitas ad-hoc: tidak ada pihak ketiga terdaftar, tidak ada RoPA.
        $this->transfer('Singapura');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Singapura', $this->negaraTerlihat());
    }

    #[Test]
    public function induk_hilang_permanen_membuat_transfer_kembali_tanpa_divisi(): void
    {
        $pihak = $this->pihakKetiga('PT Bayar', 'Keuangan');
        $this->transfer('Jepang', ['vendor_id' => $pihak->id]);

        $pihak->forceDelete();

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Jepang', $this->negaraTerlihat());
    }

    #[Test]
    public function induk_dihapus_lunak_divisinya_tetap_berlaku(): void
    {
        $pihak = $this->pihakKetiga('PT Bayar', 'Keuangan');
        $this->transfer('Jepang', ['vendor_id' => $pihak->id]);

        $pihak->delete();

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertNotContains('Jepang', $this->negaraTerlihat());
    }

    #[Test]
    public function pihak_ketiga_semua_divisi_terlihat_semua_orang(): void
    {
        $this->transfer('Singapura', ['vendor_id' => $this->pihakKetiga('PT Umum', AssignmentScope::SEMUA)->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Singapura', $this->negaraTerlihat());
    }

    #[Test]
    public function dpo_melihat_seluruh_tenant(): void
    {
        $this->transfer('Singapura', ['vendor_id' => $this->pihakKetiga('PT Talenta', 'HR')->id]);
        $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $negara = $this->negaraTerlihat();
        $this->assertContains('Singapura', $negara);
        $this->assertContains('Jepang', $negara);
    }

    #[Test]
    public function admin_tenant_berizin_bintang_juga_lintas_divisi(): void
    {
        $this->transfer('Singapura', ['vendor_id' => $this->pihakKetiga('PT Talenta', 'HR')->id]);
        $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'Kepala Kepatuhan', 'HR', ['*']));
        $this->assertCount(2, $this->negaraTerlihat());
    }

    #[Test]
    public function detail_transfer_divisi_lain_tidak_bisa_dibuka(): void
    {
        $t = $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->getJson("/api/cross-border/{$t->id}")->assertNotFound();
    }

    #[Test]
    public function transfer_divisi_lain_tidak_bisa_diubah(): void
    {
        $t = $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->putJson("/api/cross-border/{$t->id}", ['transfer_purpose' => 'Disusupi'])->assertNotFound();

        $this->assertSame('Hosting', $t->fresh()->transfer_purpose);
    }

    #[Test]
    public function transfer_divisi_lain_tidak_bisa_dihapus(): void
    {
        $t = $this->transfer('Jepang', ['vendor_id' => $this->pihakKetiga('PT Bayar', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->deleteJson("/api/cross-border/{$t->id}")->assertNotFound();

        $this->assertNull($t->fresh()->deleted_at);
    }
}
