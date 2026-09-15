<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\AiAgentToolExecutor;
use App\Support\AssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batas divisi pada AI Agent.
 *
 * AI tidak boleh melihat lebih banyak daripada yang orangnya lihat di UI biasa.
 * Aturannya sendiri milik AssignmentScope; yang diuji di sini adalah bahwa
 * executor benar-benar MEMASANGNYA — dan, yang lebih penting, bahwa ia GAGAL
 * KERAS kalau pemanggilnya lupa memasangnya.
 *
 * Bentuk lamanya (`->when($actingUser, …)`) gagal TERBUKA: tanpa user, saringan
 * divisi lenyap tanpa satu pun tanda dan AI membaca seluruh tenant. Itu bukan
 * kemungkinan teoretis — AiChatController dan ProcessAiJob memang membuat
 * executor tanpa `actingAs()`.
 */
class AiAgentDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private function peran(string $nama, array $izin): TenantRole
    {
        return TenantRole::create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'slug' => 'role-'.uniqid(),
            'permissions' => $izin,
        ]);
    }

    /**
     * Divisi seorang pengguna datang dari relasi `department`, bukan kolom di
     * `users` — lihat AssignmentScope::terapkan yang membacanya lewat
     * `optional($user->department)->name`.
     */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['ropa']): User
    {
        $departemen = $divisi ? Department::create([
            'org_id' => $this->org->id,
            'name' => $divisi,
        ]) : null;

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => $this->peran($namaPeran, $izin)->id,
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    public function test_staf_hanya_melihat_ropa_divisinya(): void
    {
        $this->ropa('Rekrutmen karyawan', 'HR');
        $this->ropa('Penagihan pelanggan', 'Keuangan');

        $staf = $this->pengguna('maker', 'staff', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('list_ropa', []);

        $kegiatan = array_column($hasil, 'processing_activity');
        $this->assertContains('Rekrutmen karyawan', $kegiatan);
        $this->assertNotContains('Penagihan pelanggan', $kegiatan);
    }

    public function test_ropa_lintas_divisi_ikut_terlihat(): void
    {
        // "(All Group)" berarti berlaku untuk semua divisi — bukan milik
        // siapa-siapa, jadi semua orang berhak melihatnya.
        $this->ropa('Kebijakan privasi perusahaan', '(All Group)');

        $staf = $this->pengguna('maker', 'staff', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('list_ropa', []);

        $this->assertContains('Kebijakan privasi perusahaan', array_column($hasil, 'processing_activity'));
    }

    public function test_ropa_yang_ditugaskan_ke_divisi_lain_ikut_terlihat_meski_bukan_divisi_utamanya(): void
    {
        // Penugasan ganda: kegiatannya milik Keuangan TETAPI HR ikut ditugaskan.
        $this->ropa('Pembayaran gaji', 'Keuangan'.AssignmentScope::DELIM.'HR');

        $staf = $this->pengguna('maker', 'staff', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('list_ropa', []);

        $this->assertContains('Pembayaran gaji', array_column($hasil, 'processing_activity'));
    }

    public function test_dpo_melihat_seluruh_tenant_lintas_divisi(): void
    {
        $this->ropa('Rekrutmen karyawan', 'HR');
        $this->ropa('Penagihan pelanggan', 'Keuangan');

        $dpo = $this->pengguna('dpo', 'dpo', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($dpo)
            ->execute('list_ropa', []);

        $kegiatan = array_column($hasil, 'processing_activity');
        $this->assertContains('Rekrutmen karyawan', $kegiatan);
        $this->assertContains('Penagihan pelanggan', $kegiatan);
    }

    public function test_admin_tenant_berizin_bintang_juga_lintas_divisi(): void
    {
        // Admin tenant sering memakai NAMA role kustom tetapi berizin '*'.
        $this->ropa('Rekrutmen karyawan', 'HR');
        $this->ropa('Penagihan pelanggan', 'Keuangan');

        $admin = $this->pengguna('maker', 'Kepala Kepatuhan', 'HR', ['*']);

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($admin)
            ->execute('list_ropa', []);

        $this->assertCount(2, $hasil);
    }

    public function test_tanpa_acting_user_executor_melempar_bukan_membuka_semuanya(): void
    {
        $this->ropa('Rekrutmen karyawan', 'HR');
        $this->ropa('Penagihan pelanggan', 'Keuangan');

        // Inilah perilaku yang dulu salah: tanpa user, saringannya hilang dan
        // seluruh tenant terbaca. Sekarang gagal keras.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/actingAs/');

        (new AiAgentToolExecutor($this->org->id))->execute('list_ropa', []);
    }

    public function test_detail_ropa_divisi_lain_tidak_bisa_dibuka(): void
    {
        $lain = $this->ropa('Penagihan pelanggan', 'Keuangan');
        $staf = $this->pengguna('maker', 'staff', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('get_ropa_detail', ['id' => $lain->id]);

        // Tidak boleh mengembalikan isinya — batas lihat harus sama dengan UI.
        $this->assertStringNotContainsString('Penagihan pelanggan', json_encode($hasil));
    }
}
