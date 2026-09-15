<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AiAgentToolExecutor;
use App\Support\AssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penugasan divisi otomatis saat membuat, dan kuncinya saat mengubah.
 *
 * Sebelum ini `assign_group` hanya diisi dari payload, dan NULL berarti
 * "(All Group)" di AssignmentScope — sehingga staf HR yang membuat RoPA
 * menghasilkan record yang terbaca SELURUH tenant. Seluruh penyaringan divisi
 * praktis diam untuk record baru.
 *
 * Yang diuji: divisinya benar-benar masuk di tiap jalur tulis, kuncinya
 * ditegakkan DI SERVER (bukan sekadar disembunyikan di UI), dan yang bekerja
 * lintas divisi tidak ikut terkunci.
 */
class AutoAssignDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(
        string $role,
        string $namaPeran,
        ?string $divisi,
        array $izin = ['ropa:read', 'ropa:write', 'vendor_risk:read', 'vendor_risk:write'],
    ): User {
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

    /**
     * Divisi asal langsung dari basis data.
     *
     * Respons create TIDAK memuat kunci `origin_division` ketika nilainya tidak
     * pernah disetel — atribut yang tak pernah diisi memang tidak ikut
     * terserialisasi. Membacanya dari record menghilangkan keraguan itu.
     */
    private function divisiAsal(string $id): ?string
    {
        return Ropa::withoutGlobalScope('org')->find($id)?->origin_division;
    }

    /** @param array<string, mixed> $tambahan */
    private function buatRopa(array $tambahan = []): array
    {
        return $this->postJson('/api/m/ropa', array_merge([
            'processing_activity' => 'Rekrutmen karyawan',
            'status' => 'draft',
        ], $tambahan))->assertSuccessful()->json('data');
    }

    #[Test]
    public function ropa_buatan_staf_otomatis_ditugaskan_ke_divisinya(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $ropa = $this->buatRopa();

        $this->assertSame('HR', $ropa['assign_group']);
        $this->assertSame('HR', $ropa['origin_division']);
    }

    #[Test]
    public function ropa_baru_tidak_lagi_terbaca_divisi_lain(): void
    {
        // Inti masalahnya: sebelum ini assign_group jatuh ke NULL = "(All Group)".
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $this->buatRopa();

        $staffKeuangan = $this->pengguna('maker', 'staff', 'Keuangan');
        Sanctum::actingAs($staffKeuangan);
        $this->buatRopa(['processing_activity' => 'Penagihan pelanggan']);

        $kegiatan = array_column($this->getJson('/api/m/ropa')->assertOk()->json('data'), 'processing_activity');

        // Pembanding positif WAJIB ada. Tanpa ini assertNotContains lolos
        // begitu saja setiap kali daftarnya kosong karena sebab lain — dan
        // uji ini pernah benar-benar begitu: ia tetap hijau saat auto-assign
        // sengaja dimatikan.
        $this->assertContains('Penagihan pelanggan', $kegiatan);
        $this->assertNotContains('Rekrutmen karyawan', $kegiatan);
    }

    #[Test]
    public function divisi_lain_boleh_ditambahkan_saat_membuat(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $ropa = $this->buatRopa(['assign_group' => 'Keuangan']);

        // Yang diminta dihormati, divisi asalnya ditambahkan — bukan menimpa.
        $this->assertSame('Keuangan'.AssignmentScope::DELIM.'HR', $ropa['assign_group']);
        $this->assertSame('HR', $ropa['origin_division']);
    }

    #[Test]
    public function penugasan_boleh_diperluas_ke_semua_divisi(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $ropa = $this->buatRopa(['assign_group' => AssignmentScope::SEMUA]);

        $this->assertSame(AssignmentScope::SEMUA, $ropa['assign_group']);
    }

    #[Test]
    public function divisi_asal_tidak_bisa_dilepas_saat_mengubah(): void
    {
        $staf = $this->pengguna('maker', 'staff', 'HR');
        Sanctum::actingAs($staf);
        $ropa = $this->buatRopa();

        // Percobaan memindahkan penugasan ke divisi lain sepenuhnya.
        $this->putJson("/api/m/ropa/{$ropa['id']}", ['assign_group' => 'Keuangan'])->assertSuccessful();

        $this->assertSame(
            'Keuangan'.AssignmentScope::DELIM.'HR',
            Ropa::withoutGlobalScope('org')->find($ropa['id'])->assign_group,
        );
    }

    #[Test]
    public function kunci_tidak_bisa_dipindah_lewat_payload(): void
    {
        // Tanpa penjagaan, satu baris di payload cukup untuk melepas kunci.
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $ropa = $this->buatRopa();

        $this->putJson("/api/m/ropa/{$ropa['id']}", [
            'origin_division' => 'Keuangan',
            'assign_group' => 'Keuangan',
        ])->assertSuccessful();

        $segar = Ropa::withoutGlobalScope('org')->find($ropa['id']);
        $this->assertSame('HR', $segar->origin_division);
        $this->assertSame('Keuangan'.AssignmentScope::DELIM.'HR', $segar->assign_group);
    }

    #[Test]
    public function dpo_tidak_terkunci_ke_divisinya(): void
    {
        // DPO sering membuatkan untuk divisi lain — mengunci mereka justru
        // menghalangi pekerjaannya.
        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $ropa = $this->buatRopa(['assign_group' => 'Keuangan']);

        $this->assertSame('Keuangan', $ropa['assign_group']);
        $this->assertNull($this->divisiAsal($ropa['id']));
    }

    #[Test]
    public function admin_tenant_juga_tidak_terkunci(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'Kepala Kepatuhan', 'HR', ['*']));

        $ropa = $this->buatRopa(['assign_group' => 'Keuangan']);

        $this->assertSame('Keuangan', $ropa['assign_group']);
        $this->assertNull($this->divisiAsal($ropa['id']));
    }

    #[Test]
    public function pembuat_tanpa_divisi_tidak_mengunci_apa_pun(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', null));

        $ropa = $this->buatRopa();

        $this->assertNull($this->divisiAsal($ropa['id']));
    }

    #[Test]
    public function pihak_ketiga_ikut_aturan_yang_sama(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $pihak = $this->postJson('/api/vendor-risk', [
            'name' => 'PT Awan Data',
        ])->assertSuccessful()->json('data');

        $this->assertSame('HR', $pihak['assign_group']);
        $this->assertSame('HR', $pihak['origin_division']);
    }

    #[Test]
    public function agen_ai_membuat_atas_nama_orangnya_jadi_ikut_terkunci(): void
    {
        $staf = $this->pengguna('maker', 'staff', 'HR');

        [$hasil] = (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('create_ropa', ['processing_activity' => 'Rekrutmen lewat agen'], true);

        $ropa = Ropa::withoutGlobalScope('org')
            ->where('processing_activity', 'Rekrutmen lewat agen')
            ->firstOrFail();

        $this->assertSame('HR', $ropa->origin_division);
        $this->assertSame('HR', $ropa->assign_group);
        $this->assertNotEmpty($hasil);
    }

    #[Test]
    public function agen_ai_tidak_bisa_menentukan_divisi_asal_sendiri(): void
    {
        $staf = $this->pengguna('maker', 'staff', 'HR');

        (new AiAgentToolExecutor($this->org->id))
            ->actingAs($staf)
            ->execute('create_ropa', [
                'processing_activity' => 'Percobaan suntik',
                'origin_division' => 'Keuangan',
            ], true);

        $ropa = Ropa::withoutGlobalScope('org')
            ->where('processing_activity', 'Percobaan suntik')
            ->firstOrFail();

        $this->assertSame('HR', $ropa->origin_division);
    }

    #[Test]
    public function record_lama_tanpa_divisi_asal_tetap_bisa_diubah_bebas(): void
    {
        // Migrasinya tidak mem-backfill apa pun. Record lama tetap NULL, dan
        // penugasannya harus tetap bisa diatur tanpa ada yang terkunci.
        $lama = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -6),
            'processing_activity' => 'Kegiatan lama',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->putJson("/api/m/ropa/{$lama->id}", ['assign_group' => 'Keuangan'])->assertSuccessful();

        $this->assertSame('Keuangan', Ropa::withoutGlobalScope('org')->find($lama->id)->assign_group);
    }

    #[Test]
    public function kunci_tetap_di_divisi_lama_saat_pembuatnya_pindah(): void
    {
        // Kuncinya fakta tentang RECORD, bukan tentang orangnya hari ini.
        $staf = $this->pengguna('maker', 'staff', 'HR');
        Sanctum::actingAs($staf);
        $ropa = $this->buatRopa();

        $keuangan = Department::create(['org_id' => $this->org->id, 'name' => 'Keuangan']);
        $staf->update(['department_id' => $keuangan->id]);

        $this->assertSame('HR', Ropa::withoutGlobalScope('org')->find($ropa['id'])->origin_division);
    }

    #[Test]
    public function pembuat_tetap_melihat_recordnya_setelah_pindah_divisi(): void
    {
        // Konsekuensi dari uji di atas yang harus tetap benar: klausa
        // `created_by` di AssignmentScope menjaganya tidak kehilangan akses.
        $staf = $this->pengguna('maker', 'staff', 'HR');
        Sanctum::actingAs($staf);
        $this->buatRopa();

        $keuangan = Department::create(['org_id' => $this->org->id, 'name' => 'Keuangan']);
        $staf->update(['department_id' => $keuangan->id]);
        Sanctum::actingAs($staf->fresh());

        $terlihat = $this->getJson('/api/m/ropa')->assertOk()->json('data');
        $this->assertContains('Rekrutmen karyawan', array_column($terlihat, 'processing_activity'));
    }

    #[Test]
    public function pihak_ketiga_divisi_asal_tidak_bisa_dilepas_saat_mengubah(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));
        $pihak = $this->postJson('/api/vendor-risk', ['name' => 'PT Awan Data'])
            ->assertSuccessful()->json('data');

        $this->putJson("/api/vendor-risk/{$pihak['id']}", ['assign_group' => 'Keuangan'])->assertSuccessful();

        $this->assertSame(
            'Keuangan'.AssignmentScope::DELIM.'HR',
            Vendor::withoutGlobalScope('org')->find($pihak['id'])->assign_group,
        );
    }
}
