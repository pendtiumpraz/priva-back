<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\Department;
use App\Models\Dpia;
use App\Models\InformationSystem;
use App\Models\LiaAssessment;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batas divisi pada LIA, Insiden, dan Sistem Informasi — DITURUNKAN dari induk.
 *
 * Ketiganya tidak punya kolom penugasan sendiri, dan bentuk tautannya berbeda:
 *
 *   LIA     → kolom FK ke RoPA dan DPIA
 *   Insiden → kolom FK + DUA larik JSON (RoPA terdampak, pihak ketiga terlibat)
 *   Sistem  → hanya lewat DUA TABEL PIVOT
 *
 * Aturannya SALAH SATU di ketiganya.
 */
class LiaBreachSistemDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['lia', 'breach', 'data_discovery']): User
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

    private function dpia(string $judul, ?string $divisi): Dpia
    {
        return Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-'.substr(uniqid(), -6),
            'description' => $judul,
            'assign_group' => $divisi,
            'status' => 'draft',
        ]);
    }

    private function pihakKetiga(string $nama, ?string $divisi): Vendor
    {
        return Vendor::create(['org_id' => $this->org->id, 'name' => $nama, 'assign_group' => $divisi]);
    }

    // ---------- LIA ----------

    /** @param array<string, mixed> $tautan */
    private function lia(string $judul, array $tautan = []): LiaAssessment
    {
        return LiaAssessment::create(array_merge([
            'org_id' => $this->org->id,
            'lia_code' => 'LIA-'.substr(uniqid(), -8),
            'title' => $judul,
            'processing_activity' => $judul,
            'status' => LiaAssessment::STATUS_DRAFT,
        ], $tautan));
    }

    /** @return array<int, string> */
    private function liaTerlihat(): array
    {
        return array_column($this->getJson('/api/lia?per_page=200')->assertOk()->json('data.data'), 'title');
    }

    #[Test]
    public function lia_ikut_divisi_ropa_yang_ditautkan(): void
    {
        $this->lia('LIA Rekrutmen', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->lia('LIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->liaTerlihat();
        $this->assertContains('LIA Rekrutmen', $judul);
        $this->assertNotContains('LIA Penagihan', $judul);
    }

    #[Test]
    public function lia_ikut_divisi_dpia_yang_ditautkan(): void
    {
        $this->lia('LIA lewat DPIA', ['linked_dpia_id' => $this->dpia('DPIA Rekrutmen', 'HR')->id]);
        $this->lia('LIA DPIA lain', ['linked_dpia_id' => $this->dpia('DPIA Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->liaTerlihat();
        $this->assertContains('LIA lewat DPIA', $judul);
        $this->assertNotContains('LIA DPIA lain', $judul);
    }

    #[Test]
    public function lia_cukup_salah_satu_induk_terlihat(): void
    {
        // RoPA milik Keuangan, DPIA milik HR — keduanya berhak.
        $this->lia('LIA Campuran', [
            'linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id,
            'linked_dpia_id' => $this->dpia('DPIA Rekrutmen', 'HR')->id,
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('LIA Campuran', $this->liaTerlihat());
    }

    #[Test]
    public function lia_tanpa_induk_terlihat_semua_divisi(): void
    {
        $this->lia('LIA Lepas');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('LIA Lepas', $this->liaTerlihat());
    }

    #[Test]
    public function lia_divisi_lain_tidak_bisa_dibuka(): void
    {
        $r = $this->lia('LIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->getJson("/api/lia/{$r->id}")->assertNotFound();
    }

    #[Test]
    public function lia_tidak_bisa_dibuat_dari_ropa_divisi_lain(): void
    {
        // 13 field RoPA ikut tersalin sebagai snapshot — datanya berpindah tangan.
        $lain = $this->ropa('Penagihan', 'Keuangan');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->postJson("/api/lia/from-ropa/{$lain->id}")->assertNotFound();

        $this->assertDatabaseCount('lia_assessments', 0);
    }

    #[Test]
    public function dpo_melihat_seluruh_lia_tenant(): void
    {
        $this->lia('LIA Rekrutmen', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->lia('LIA Penagihan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $judul = $this->liaTerlihat();
        $this->assertContains('LIA Rekrutmen', $judul);
        $this->assertContains('LIA Penagihan', $judul);
    }

    // ---------- Insiden ----------

    /** @param array<string, mixed> $tautan */
    private function insiden(string $judul, array $tautan = []): BreachIncident
    {
        return BreachIncident::create(array_merge([
            'org_id' => $this->org->id,
            'incident_code' => 'BRC-'.substr(uniqid(), -8),
            'title' => $judul,
            'severity' => 'medium',
            'status' => 'open',
        ], $tautan));
    }

    /** @return array<int, string> */
    private function insidenTerlihat(): array
    {
        return array_column($this->getJson('/api/m/breach?per_page=200')->assertOk()->json('data'), 'title');
    }

    #[Test]
    public function insiden_ikut_divisi_ropa_terdampak(): void
    {
        $this->insiden('Bocor HR', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->insiden('Bocor Keuangan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->insidenTerlihat();
        $this->assertContains('Bocor HR', $judul);
        $this->assertNotContains('Bocor Keuangan', $judul);
    }

    #[Test]
    public function insiden_ropa_tambahan_di_larik_json_ikut_memberi_akses(): void
    {
        $keuangan = $this->ropa('Penagihan', 'Keuangan');
        $hr = $this->ropa('Rekrutmen', 'HR');

        $this->insiden('Bocor Lintas Divisi', [
            'linked_ropa_id' => $keuangan->id,
            'linked_ropa_ids' => [$keuangan->id, $hr->id],
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Bocor Lintas Divisi', $this->insidenTerlihat());
    }

    #[Test]
    public function insiden_ikut_divisi_pihak_ketiga_di_larik_json(): void
    {
        // `linked_vendor_ids` tidak punya kolom tunggal padanannya — kalau
        // lariknya tidak diperiksa, jalur ini tidak pernah memberi akses.
        $this->insiden('Bocor via Pihak Ketiga', [
            'linked_vendor_ids' => [$this->pihakKetiga('PT Talenta', 'HR')->id],
        ]);
        $this->insiden('Bocor Pihak Lain', [
            'linked_vendor_ids' => [$this->pihakKetiga('PT Bayar', 'Keuangan')->id],
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->insidenTerlihat();
        $this->assertContains('Bocor via Pihak Ketiga', $judul);
        $this->assertNotContains('Bocor Pihak Lain', $judul);
    }

    #[Test]
    public function insiden_tanpa_induk_terlihat_semua_divisi(): void
    {
        $this->insiden('Bocor Belum Dipetakan');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Bocor Belum Dipetakan', $this->insidenTerlihat());
    }

    #[Test]
    public function dpo_melihat_seluruh_insiden_tenant(): void
    {
        // Kewajiban notifikasi 3x24 jam ada di tangan DPO — tidak boleh ada
        // insiden yang lolos dari pandangannya.
        $this->insiden('Bocor HR', ['linked_ropa_id' => $this->ropa('Rekrutmen', 'HR')->id]);
        $this->insiden('Bocor Keuangan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $judul = $this->insidenTerlihat();
        $this->assertContains('Bocor HR', $judul);
        $this->assertContains('Bocor Keuangan', $judul);
    }

    #[Test]
    public function insiden_divisi_lain_tidak_bisa_dibuka(): void
    {
        $r = $this->insiden('Bocor Keuangan', ['linked_ropa_id' => $this->ropa('Penagihan', 'Keuangan')->id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->getJson("/api/m/breach/{$r->id}")->assertNotFound();
    }

    // ---------- Sistem Informasi ----------

    private function sistem(string $nama): InformationSystem
    {
        return InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'source_type' => 'database',
        ]);
    }

    private function tautkanSistemKeRopa(InformationSystem $sistem, Ropa $ropa): void
    {
        DB::table('information_system_ropa')->insert([
            'information_system_id' => $sistem->id,
            'ropa_id' => $ropa->id,
            'org_id' => $this->org->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function sistemTerlihat(): array
    {
        return array_column($this->getJson('/api/m/data-discovery?per_page=200')->assertOk()->json('data'), 'name');
    }

    #[Test]
    public function sistem_ikut_divisi_ropa_lewat_pivot(): void
    {
        $this->tautkanSistemKeRopa($this->sistem('HRIS'), $this->ropa('Rekrutmen', 'HR'));
        $this->tautkanSistemKeRopa($this->sistem('Core Banking'), $this->ropa('Penagihan', 'Keuangan'));

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $nama = $this->sistemTerlihat();
        $this->assertContains('HRIS', $nama);
        $this->assertNotContains('Core Banking', $nama);
    }

    #[Test]
    public function sistem_yang_dipakai_dua_divisi_terlihat_keduanya(): void
    {
        $bersama = $this->sistem('Data Warehouse');
        $this->tautkanSistemKeRopa($bersama, $this->ropa('Penagihan', 'Keuangan'));
        $this->tautkanSistemKeRopa($bersama, $this->ropa('Rekrutmen', 'HR'));

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Data Warehouse', $this->sistemTerlihat());
    }

    #[Test]
    public function sistem_belum_ditautkan_terlihat_semua_divisi(): void
    {
        // Hasil pindaian baru belum tahu miliknya siapa. Menyembunyikannya akan
        // membuat tak seorang pun bisa menautkannya ke RoPA — mengunci diri.
        $this->sistem('Hasil Scan Baru');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->assertContains('Hasil Scan Baru', $this->sistemTerlihat());
    }

    #[Test]
    public function sistem_divisi_lain_tidak_bisa_dibuka(): void
    {
        $sistem = $this->sistem('Core Banking');
        $this->tautkanSistemKeRopa($sistem, $this->ropa('Penagihan', 'Keuangan'));

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->getJson("/api/m/data-discovery/{$sistem->id}")->assertNotFound();
    }

    #[Test]
    public function dpo_melihat_seluruh_sistem_tenant(): void
    {
        $this->tautkanSistemKeRopa($this->sistem('HRIS'), $this->ropa('Rekrutmen', 'HR'));
        $this->tautkanSistemKeRopa($this->sistem('Core Banking'), $this->ropa('Penagihan', 'Keuangan'));

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $nama = $this->sistemTerlihat();
        $this->assertContains('HRIS', $nama);
        $this->assertContains('Core Banking', $nama);
    }
}
