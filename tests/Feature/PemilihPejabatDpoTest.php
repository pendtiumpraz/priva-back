<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemilih "Pejabat PDP (DPO)" di wizard RoPA hanya boleh menawarkan pemegang
 * peran DPO.
 *
 * Endpoint /dpo-users namanya menyesatkan: ia mengembalikan SELURUH user aktif,
 * dan itu memang harus begitu — satu daftar yang sama memberi makan tiga
 * pemilih di wizard RoPA (Pejabat PDP, Process Owner/PIC, dan PIC penerima
 * internal), dan dua yang terakhir terbuka untuk siapa pun. Karena itu yang
 * diuji di sini adalah PENANDANYA: `is_dpo` harus benar untuk kedua cara
 * menandai DPO, supaya penyaringan di sisi pemilih punya dasar yang benar.
 */
class PemilihPejabatDpoTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $nama, string $role, string $namaPeran, array $izin = ['ropa']): User
    {
        return User::factory()->create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'role' => $role,
            'is_active' => true,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => $namaPeran,
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
        ]);
    }

    /** @return array<string, bool> nama → is_dpo */
    private function penanda(): array
    {
        $baris = $this->getJson('/api/dpo-users')->assertOk()->json('data');

        return array_combine(
            array_column($baris, 'name'),
            array_map(fn ($b) => (bool) ($b['is_dpo'] ?? false), $baris),
        );
    }

    #[Test]
    public function seluruh_user_aktif_tetap_dikembalikan(): void
    {
        // Menyaring endpoint-nya akan mengosongkan pemilih Process Owner/PIC
        // dan PIC penerima internal, yang justru harus terbuka untuk siapa pun.
        $this->pengguna('Dewi DPO', 'dpo', 'dpo');
        $this->pengguna('Budi Staf', 'maker', 'staff');
        $this->pengguna('Ani Admin', 'admin', 'admin', ['*']);

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $this->assertCount(4, $this->penanda());
    }

    #[Test]
    public function penanda_benar_untuk_role_global_dpo(): void
    {
        $this->pengguna('Dewi DPO', 'dpo', 'dpo');
        $this->pengguna('Budi Staf', 'maker', 'staff');

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $penanda = $this->penanda();
        $this->assertTrue($penanda['Dewi DPO']);
        $this->assertFalse($penanda['Budi Staf']);
    }

    #[Test]
    public function penanda_benar_untuk_tenant_role_bernama_dpo(): void
    {
        // Tenant yang menandai DPO lewat role kustom bernama "DPO" tidak boleh
        // berakhir dengan pemilih Pejabat PDP yang kosong.
        $this->pengguna('Citra DPO', 'maker', 'DPO', ['*']);

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $this->assertTrue($this->penanda()['Citra DPO']);
    }

    #[Test]
    public function admin_tenant_bukan_pejabat_dpo(): void
    {
        // Admin tenant melihat seluruh divisi, tapi itu TIDAK menjadikannya
        // Pejabat PDP — jabatan itu punya kewajiban hukum tersendiri.
        $this->pengguna('Ani Admin', 'admin', 'admin', ['*']);

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $this->assertFalse($this->penanda()['Ani Admin']);
    }

    #[Test]
    public function izin_bintang_saja_bukan_penanda_dpo(): void
    {
        // '*' berarti "semua modul", bukan "pejabat PDP". Menyamakan keduanya
        // akan membuat tiap admin berizin penuh tercatat sebagai DPO di RoPA.
        $this->pengguna('Eko Kepala', 'maker', 'Kepala Kepatuhan', ['*']);

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $this->assertFalse($this->penanda()['Eko Kepala']);
    }

    #[Test]
    public function user_nonaktif_tidak_ikut_ditawarkan(): void
    {
        $nonaktif = $this->pengguna('Fajar Keluar', 'dpo', 'dpo');
        $nonaktif->update(['is_active' => false]);

        Sanctum::actingAs($this->pengguna('Pemanggil', 'maker', 'staff'));

        $this->assertArrayNotHasKey('Fajar Keluar', $this->penanda());
    }
}
