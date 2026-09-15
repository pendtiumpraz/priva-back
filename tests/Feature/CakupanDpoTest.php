<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\CakupanDpo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cakupan pandangan DPO, dan preferensi mempersempitnya.
 *
 * Dua sumbu yang sengaja dipisah: CAKUPAN (se-perusahaan / per-divisi) mengatur
 * akses; JUMLAH (satu / banyak) hanya membatasi berapa akun boleh memegang
 * peran DPO dan tidak menyentuh akses sama sekali.
 */
class CakupanDpoTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['*']): User
    {
        $departemen = $divisi ? Department::firstOrCreate([
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

    private function aturCakupan(string $cakupan): void
    {
        $pengaturan = $this->org->settings ?? [];
        $pengaturan[CakupanDpo::KUNCI_CAKUPAN] = $cakupan;
        $this->org->settings = $pengaturan;
        $this->org->save();
    }

    /** @return array<int, string> */
    private function ropaTerlihat(): array
    {
        return array_column(
            $this->getJson('/api/m/ropa?per_page=200')->assertOk()->json('data'),
            'processing_activity',
        );
    }

    #[Test]
    public function bawaannya_dpo_melihat_seluruh_perusahaan(): void
    {
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $this->assertCount(2, $this->ropaTerlihat());
    }

    #[Test]
    public function cakupan_per_divisi_membatasi_dpo_ke_divisinya(): void
    {
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        $this->aturCakupan(CakupanDpo::PER_DIVISI);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $kegiatan = $this->ropaTerlihat();
        $this->assertContains('Rekrutmen', $kegiatan);
        $this->assertNotContains('Penagihan', $kegiatan);
    }

    #[Test]
    public function izin_bintang_tidak_membatalkan_cakupan_per_divisi(): void
    {
        // Jebakannya: DPO lazimnya berizin '*' atas semua modul. Kalau
        // pemeriksaan '*' didahulukan, settingnya tidak pernah berarti apa-apa.
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        $this->aturCakupan(CakupanDpo::PER_DIVISI);

        Sanctum::actingAs($this->pengguna('maker', 'DPO', 'HR', ['*']));

        $this->assertNotContains('Penagihan', $this->ropaTerlihat());
    }

    #[Test]
    public function admin_tenant_tetap_lintas_divisi_apa_pun_cakupan_dpo(): void
    {
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        $this->aturCakupan(CakupanDpo::PER_DIVISI);

        Sanctum::actingAs($this->pengguna('maker', 'Kepala Kepatuhan', 'HR', ['*']));

        $this->assertCount(2, $this->ropaTerlihat());
    }

    #[Test]
    public function dpo_dapat_mempersempit_pandangannya_ke_satu_divisi(): void
    {
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        Department::firstOrCreate(['org_id' => $this->org->id, 'name' => 'Keuangan']);

        $dpo = $this->pengguna('dpo', 'dpo', 'HR');
        Sanctum::actingAs($dpo);

        $this->putJson('/api/me/dpo-view', ['division' => 'Keuangan'])
            ->assertOk()
            ->assertJsonPath('data.berlaku', true);

        Sanctum::actingAs($dpo->fresh());
        $kegiatan = $this->ropaTerlihat();

        // Dipersempit ke Keuangan — divisi akunnya sendiri (HR) justru tidak.
        $this->assertContains('Penagihan', $kegiatan);
        $this->assertNotContains('Rekrutmen', $kegiatan);
    }

    #[Test]
    public function dpo_dapat_kembali_melihat_seluruh_perusahaan(): void
    {
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        Department::firstOrCreate(['org_id' => $this->org->id, 'name' => 'Keuangan']);

        $dpo = $this->pengguna('dpo', 'dpo', 'HR');
        Sanctum::actingAs($dpo);
        $this->putJson('/api/me/dpo-view', ['division' => 'Keuangan'])->assertOk();

        Sanctum::actingAs($dpo->fresh());
        $this->putJson('/api/me/dpo-view', ['division' => null])->assertOk();

        Sanctum::actingAs($dpo->fresh());
        $this->assertCount(2, $this->ropaTerlihat());
    }

    #[Test]
    public function pandangan_hanya_mempersempit_tidak_pernah_memperluas(): void
    {
        // INTI KEAMANANNYA. Staf biasa yang menulis preferensi ini tidak boleh
        // mendapat akses apa pun ke divisi yang disebutnya.
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        Department::firstOrCreate(['org_id' => $this->org->id, 'name' => 'Keuangan']);

        $staf = $this->pengguna('maker', 'staff', 'HR', ['ropa:read', 'ropa:write']);
        Sanctum::actingAs($staf);

        $this->putJson('/api/me/dpo-view', ['division' => 'Keuangan'])
            ->assertOk()
            // Tersimpan, tapi dikatakan terus terang bahwa ia tidak berlaku.
            ->assertJsonPath('data.berlaku', false);

        Sanctum::actingAs($staf->fresh());
        $kegiatan = $this->ropaTerlihat();

        $this->assertContains('Rekrutmen', $kegiatan);
        $this->assertNotContains('Penagihan', $kegiatan);
    }

    #[Test]
    public function pandangan_dpo_per_divisi_diabaikan(): void
    {
        // Batasnya sudah datang dari divisinya sendiri; preferensi tidak boleh
        // memindahkannya ke divisi lain.
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');
        Department::firstOrCreate(['org_id' => $this->org->id, 'name' => 'Keuangan']);
        $this->aturCakupan(CakupanDpo::PER_DIVISI);

        $dpo = $this->pengguna('dpo', 'dpo', 'HR');
        Sanctum::actingAs($dpo);
        $this->putJson('/api/me/dpo-view', ['division' => 'Keuangan'])
            ->assertOk()
            ->assertJsonPath('data.berlaku', false);

        Sanctum::actingAs($dpo->fresh());
        $kegiatan = $this->ropaTerlihat();

        $this->assertContains('Rekrutmen', $kegiatan);
        $this->assertNotContains('Penagihan', $kegiatan);
    }

    #[Test]
    public function divisi_yang_tidak_ada_ditolak(): void
    {
        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $this->putJson('/api/me/dpo-view', ['division' => 'Divisi Khayalan'])
            ->assertStatus(422);
    }

    // ---------- Pengaturan oleh admin tenant ----------

    #[Test]
    public function admin_tenant_dapat_mengatur_cakupan(): void
    {
        Sanctum::actingAs($this->pengguna('admin', 'admin', 'HR'));

        $this->putJson('/api/organization/dpo-scope', [
            'dpo_scope' => CakupanDpo::PER_DIVISI,
            'dpo_jumlah' => CakupanDpo::BANYAK,
        ])->assertOk();

        $this->assertSame(
            CakupanDpo::PER_DIVISI,
            Organization::find($this->org->id)->settings[CakupanDpo::KUNCI_CAKUPAN],
        );
    }

    #[Test]
    public function dpo_tidak_dapat_mengatur_cakupannya_sendiri(): void
    {
        // Kalau bisa, cakupannya berhenti jadi batas dan berubah jadi preferensi
        // yang bisa dilonggarkan sendiri oleh pihak yang dibatasinya.
        $this->aturCakupan(CakupanDpo::PER_DIVISI);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $this->putJson('/api/organization/dpo-scope', [
            'dpo_scope' => CakupanDpo::SE_PERUSAHAAN,
            'dpo_jumlah' => CakupanDpo::BANYAK,
        ])->assertForbidden();

        $this->assertSame(
            CakupanDpo::PER_DIVISI,
            Organization::find($this->org->id)->settings[CakupanDpo::KUNCI_CAKUPAN],
        );
    }

    #[Test]
    public function dpo_berizin_bintang_juga_ditolak(): void
    {
        // Jalur '*' adalah satu-satunya tempat DPO bisa menyelinap sebagai admin.
        Sanctum::actingAs($this->pengguna('maker', 'DPO', 'HR', ['*']));

        $this->putJson('/api/organization/dpo-scope', [
            'dpo_scope' => CakupanDpo::SE_PERUSAHAAN,
            'dpo_jumlah' => CakupanDpo::BANYAK,
        ])->assertForbidden();
    }

    #[Test]
    public function staf_biasa_tidak_dapat_mengatur_cakupan(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', ['ropa:read']));

        $this->putJson('/api/organization/dpo-scope', [
            'dpo_scope' => CakupanDpo::PER_DIVISI,
            'dpo_jumlah' => CakupanDpo::BANYAK,
        ])->assertForbidden();
    }

    #[Test]
    public function mode_satu_dpo_ditolak_selagi_dpo_masih_banyak(): void
    {
        $this->pengguna('dpo', 'dpo', 'HR');
        $this->pengguna('dpo', 'dpo', 'Keuangan');

        Sanctum::actingAs($this->pengguna('admin', 'admin', 'HR'));

        $this->putJson('/api/organization/dpo-scope', [
            'dpo_scope' => CakupanDpo::SE_PERUSAHAAN,
            'dpo_jumlah' => CakupanDpo::SATU,
        ])
            ->assertStatus(422)
            ->assertJsonPath('jumlah_dpo_terdaftar', 2);
    }

    #[Test]
    public function jumlah_dpo_tidak_memengaruhi_akses(): void
    {
        // Sumbu JUMLAH murni batasan jumlah akun — bukan kontrol akses.
        $this->ropa('Rekrutmen', 'HR');
        $this->ropa('Penagihan', 'Keuangan');

        $pengaturan = $this->org->settings ?? [];
        $pengaturan[CakupanDpo::KUNCI_JUMLAH] = CakupanDpo::SATU;
        $this->org->settings = $pengaturan;
        $this->org->save();

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $this->assertCount(2, $this->ropaTerlihat());
    }

    #[Test]
    public function ringkasan_menyebut_jumlah_dpo_dan_hak_mengatur(): void
    {
        $this->pengguna('dpo', 'dpo', 'Keuangan');

        Sanctum::actingAs($this->pengguna('admin', 'admin', 'HR'));

        $this->getJson('/api/organization/dpo-scope')
            ->assertOk()
            ->assertJsonPath('data.dpo_scope', CakupanDpo::SE_PERUSAHAAN)
            ->assertJsonPath('data.dpo_jumlah', CakupanDpo::BANYAK)
            ->assertJsonPath('data.jumlah_dpo_terdaftar', 1)
            ->assertJsonPath('data.dapat_mengatur', true);
    }
}
