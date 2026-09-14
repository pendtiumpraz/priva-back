<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Dpia;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Peta koneksi wajib menyaring per DIVISI, sama seperti halaman daftarnya.
 *
 * Kebocorannya nyata dan diam: peta mengambil barisnya lewat `DB::table()`
 * mentah, yang tidak pernah kena scope Eloquent. Selama bertahun-tahun halaman
 * RoPA sudah menyembunyikan kegiatan divisi lain, tetapi menekan tombol peta di
 * baris mana pun akan menggambar seluruh isi organisasi — lengkap dengan nomor
 * registrasi, nama kegiatan, tingkat risiko, dan nama pihak ketiganya.
 *
 * Yang dikunci di sini:
 *   1. peta se-modul hanya memuat baris yang memang boleh dilihat orangnya;
 *   2. peta satu record milik divisi lain dijawab 404 — bukan peta kosong;
 *   3. tetangga milik divisi lain ikut hilang, beserta tepinya;
 *   4. item penanganan risiko (RTP) mewarisi keterlihatan DPIA induknya;
 *   5. penugasan "(All Group)", penugasan langsung ke orangnya, dan pembuat
 *      record tetap terlihat — penyaringan tidak boleh berlebihan;
 *   6. admin/DPO tetap melihat seluruh tenant.
 */
class PetaKoneksiDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $sdm;

    private Department $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->sdm = Department::create(['org_id' => $this->org->id, 'name' => 'SDM']);
        $this->keuangan = Department::create(['org_id' => $this->org->id, 'name' => 'Keuangan']);
    }

    /**
     * Pengguna biasa: role tenant TANPA izin '*'.
     *
     * Izinnya ditulis per modul dengan sengaja. Role ber-'*' menembus
     * penyaringan divisi (itu memang aturannya), jadi memakainya di sini akan
     * membuat seluruh uji ini hijau tanpa membuktikan apa pun.
     */
    private function pegawai(?Department $divisi, string $nama = 'Staf'): User
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'maker',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['ropa', 'dpia', 'vendor_risk'],
        ]);

        return User::factory()->create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'role' => 'maker',
            'tenant_role_id' => $role->id,
            'department_id' => $divisi?->id,
        ]);
    }

    private function admin(): User
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
            'department_id' => $this->keuangan->id,
        ]);
    }

    private function ropa(string $nomor, string $kegiatan, array $extra = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => $nomor,
            'processing_activity' => $kegiatan,
            'status' => 'approved',
        ], $extra));
    }

    /** @return array<int, string> */
    private function ids(array $g): array
    {
        return collect($g['nodes'])->pluck('id')->all();
    }

    private function hasEdge(array $g, string $from, string $to): bool
    {
        return collect($g['edges'])->contains(fn ($e) => $e['from'] === $from && $e['to'] === $to);
    }

    public function test_peta_se_modul_hanya_memuat_ropa_yang_boleh_dilihat(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $milikSdm = $this->ropa('ROPA-2026-001', 'Penggajian', ['assign_group' => 'SDM']);
        $milikKeuangan = $this->ropa('ROPA-2026-002', 'Rekonsiliasi Bank', ['assign_group' => 'Keuangan']);
        $semua = $this->ropa('ROPA-2026-003', 'Rekrutmen', ['assign_group' => '(All Group)']);
        $tanpaPenugasan = $this->ropa('ROPA-2026-004', 'Arsip Umum');

        $g = $this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data');
        $ids = $this->ids($g);

        $this->assertContains('ropa:'.$milikSdm->id, $ids);
        $this->assertContains('ropa:'.$semua->id, $ids, 'Penugasan "(All Group)" harus terlihat semua orang.');
        $this->assertContains('ropa:'.$tanpaPenugasan->id, $ids, 'Tanpa penugasan = milik semua, bukan milik tidak seorang pun.');
        $this->assertNotContains('ropa:'.$milikKeuangan->id, $ids);

        // Jumlah di `truncated` dihitung dari kuerinya sendiri, jadi ia ikut
        // tersaring. Kalau tidak, "menampilkan 3 dari 4" tetap membocorkan
        // bahwa ada satu baris lagi yang tidak boleh dilihat.
        $json = json_encode($g);
        $this->assertStringNotContainsString('Rekonsiliasi Bank', $json);
        $this->assertStringNotContainsString('ROPA-2026-002', $json);
    }

    public function test_divisi_ditulis_berjejer_tetap_cocok_tanpa_salah_tangkap(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $gabungan = $this->ropa('ROPA-2026-010', 'Pelatihan Bersama', ['assign_group' => 'Keuangan | SDM | Legal']);
        // 'SDMX' memuat 'SDM' sebagai awalan — tanpa jangkar delimiter, LIKE
        // akan menangkapnya dan membocorkan divisi yang namanya berimbuhan.
        $miripTapiBeda = $this->ropa('ROPA-2026-011', 'Pemrosesan Lain', ['assign_group' => 'SDMX']);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data'));

        $this->assertContains('ropa:'.$gabungan->id, $ids);
        $this->assertNotContains('ropa:'.$miripTapiBeda->id, $ids);
    }

    public function test_peta_satu_record_milik_divisi_lain_dijawab_404(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $milikKeuangan = $this->ropa('ROPA-2026-005', 'Rekonsiliasi Bank', ['assign_group' => 'Keuangan']);

        // 404, BUKAN peta kosong: peta kosong memberi tahu bahwa recordnya ada
        // dan hanya kebetulan tidak punya tautan. Jawaban ini harus sama
        // persis dengan id yang memang tidak pernah ada.
        $this->getJson("/api/peta-koneksi/ropa/{$milikKeuangan->id}")->assertNotFound();
        $this->getJson('/api/peta-koneksi/ropa/'.Str::uuid())->assertNotFound();
    }

    public function test_tetangga_milik_divisi_lain_ikut_hilang_beserta_tepinya(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $ropa = $this->ropa('ROPA-2026-006', 'Penggajian', ['assign_group' => 'SDM']);
        $dpiaSdm = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id, 'assign_group' => 'SDM',
            'registration_number' => 'DPIA-2026-001', 'status' => 'draft',
        ]);
        $dpiaKeuangan = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id, 'assign_group' => 'Keuangan',
            'registration_number' => 'DPIA-2026-002', 'status' => 'draft',
        ]);

        $g = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');
        $ids = $this->ids($g);

        $this->assertContains('dpia:'.$dpiaSdm->id, $ids);
        $this->assertNotContains('dpia:'.$dpiaKeuangan->id, $ids);
        // Simpulnya hilang, jadi tepinya harus ikut gugur — simpul yatim tetap
        // membocorkan keberadaan recordnya.
        $this->assertFalse($this->hasEdge($g, 'ropa:'.$ropa->id, 'dpia:'.$dpiaKeuangan->id));
        $this->assertStringNotContainsString('DPIA-2026-002', json_encode($g));
    }

    public function test_item_rtp_mewarisi_keterlihatan_dpia_induknya(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $ropa = $this->ropa('ROPA-2026-007', 'Penggajian', ['assign_group' => 'SDM']);
        $milikSdm = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id, 'assign_group' => 'SDM',
            'registration_number' => 'DPIA-2026-003', 'status' => 'draft',
            'mitigation_tracking' => [
                ['risk_event' => 'Enkripsi berkas slip gaji', 'status' => 'verified'],
            ],
        ]);
        $milikKeuangan = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id, 'assign_group' => 'Keuangan',
            'registration_number' => 'DPIA-2026-004', 'status' => 'draft',
            'mitigation_tracking' => [
                ['risk_event' => 'Kebocoran mutasi rekening direksi', 'status' => 'planned'],
                ['risk_event' => 'Akses berlebih tim keuangan', 'status' => 'verified'],
            ],
        ]);

        // Peta SE-MODUL: tiap DPIA jadi benih, jadi di sinilah simpul ringkasan
        // RTP lahir. Jumlahnya saja sudah bercerita — "2 item, 1 selesai"
        // memberi tahu ada pekerjaan yang belum tuntas di divisi lain.
        $ids = $this->ids($this->getJson('/api/peta-koneksi/dpia')->assertOk()->json('data'));
        $this->assertContains('rtp:'.$milikSdm->id, $ids);
        $this->assertNotContains('rtp:'.$milikKeuangan->id, $ids);

        // Peta SATU RECORD membedah itemnya satu per satu, dan judulnya justru
        // yang paling sensitif — ia menyebut kelemahan yang belum ditutup.
        $sendiri = json_encode($this->getJson("/api/peta-koneksi/dpia/{$milikSdm->id}")->assertOk()->json('data'));
        $this->assertStringContainsString('Enkripsi berkas slip gaji', $sendiri);
        $this->assertStringNotContainsString('Kebocoran mutasi rekening direksi', $sendiri);

        $this->getJson("/api/peta-koneksi/dpia/{$milikKeuangan->id}")->assertNotFound();
    }

    public function test_pihak_ketiga_divisi_lain_tidak_muncul_di_petanya(): void
    {
        Sanctum::actingAs($this->pegawai($this->sdm));

        $milikSdm = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Payroll Mitra', 'assign_group' => 'SDM']);
        $milikKeuangan = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Audit Keuangan', 'assign_group' => 'Keuangan']);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/vendor-risk')->assertOk()->json('data'));

        $this->assertContains('thirdparty:'.$milikSdm->id, $ids);
        $this->assertNotContains('thirdparty:'.$milikKeuangan->id, $ids);
    }

    public function test_ditugaskan_langsung_ke_orangnya_tetap_terlihat(): void
    {
        $staf = $this->pegawai($this->sdm);
        Sanctum::actingAs($staf);

        // Milik divisi lain, tetapi orang ini ikut ditugaskan — ia harus tetap
        // melihatnya, persis seperti di halaman daftarnya.
        $dipinjam = $this->ropa('ROPA-2026-008', 'Audit Gaji', [
            'assign_group' => 'Keuangan',
            'assignees' => [$staf->id],
        ]);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data'));
        $this->assertContains('ropa:'.$dipinjam->id, $ids);
        $this->getJson("/api/peta-koneksi/ropa/{$dipinjam->id}")->assertOk();
    }

    public function test_pembuat_record_tetap_melihat_buatannya_sendiri(): void
    {
        $staf = $this->pegawai($this->sdm);
        Sanctum::actingAs($staf);

        $buatanSendiri = $this->ropa('ROPA-2026-009', 'Catatan Pribadi Tim', [
            'assign_group' => 'Keuangan',
            'created_by' => $staf->id,
        ]);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data'));
        $this->assertContains('ropa:'.$buatanSendiri->id, $ids);
    }

    public function test_pengguna_tanpa_divisi_hanya_melihat_yang_terbuka(): void
    {
        Sanctum::actingAs($this->pegawai(null, 'Tanpa Divisi'));

        $terbuka = $this->ropa('ROPA-2026-012', 'Arsip Umum', ['assign_group' => '(All Group)']);
        $tertutup = $this->ropa('ROPA-2026-013', 'Penggajian', ['assign_group' => 'SDM']);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data'));

        $this->assertContains('ropa:'.$terbuka->id, $ids);
        $this->assertNotContains('ropa:'.$tertutup->id, $ids);
    }

    public function test_admin_tetap_melihat_seluruh_divisi(): void
    {
        Sanctum::actingAs($this->admin());

        $sdm = $this->ropa('ROPA-2026-014', 'Penggajian', ['assign_group' => 'SDM']);
        $keuangan = $this->ropa('ROPA-2026-015', 'Rekonsiliasi Bank', ['assign_group' => 'Keuangan']);

        $ids = $this->ids($this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data'));

        // Admin ada di divisi Keuangan, tetapi haknya bukan dari divisinya.
        $this->assertContains('ropa:'.$sdm->id, $ids);
        $this->assertContains('ropa:'.$keuangan->id, $ids);
        $this->getJson("/api/peta-koneksi/ropa/{$sdm->id}")->assertOk();
    }

    public function test_daftar_insiden_pihak_ketiga_ikut_tersaring(): void
    {
        $staf = $this->pegawai($this->sdm);
        Sanctum::actingAs($staf);

        $milikSdm = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Payroll Mitra', 'assign_group' => 'SDM']);
        $milikKeuangan = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Audit Keuangan', 'assign_group' => 'Keuangan']);

        foreach ([[$milikSdm, 'Kebocoran data absensi'], [$milikKeuangan, 'Kebocoran mutasi rekening']] as [$pihak, $judul]) {
            DB::table('vendor_incidents')->insert([
                'id' => (string) Str::uuid(), 'org_id' => $this->org->id,
                'vendor_id' => $pihak->id, 'title' => $judul, 'kind' => 'breach',
                'severity' => 'high', 'status' => 'open', 'reporter_user_id' => $staf->id,
                'description' => $judul.' — rincian.', 'detected_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $json = json_encode($this->getJson('/api/tprm/incidents')->assertOk()->json());

        $this->assertStringContainsString('Kebocoran data absensi', $json);
        $this->assertStringNotContainsString('Kebocoran mutasi rekening', $json);
        $this->assertStringNotContainsString('PT Audit Keuangan', $json);
    }
}
