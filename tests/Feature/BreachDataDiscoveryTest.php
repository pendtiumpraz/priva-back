<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\InformationSystem;
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
 * Data Discovery → Insiden → Pihak Ketiga.
 *
 * Sebelum ini "tipe data terdampak" pada insiden diketik manual sebagai teks
 * bebas, padahal hasil pindai sistem sudah menyimpan jawabannya per kolom
 * (`pii_detected` + `pdp_category`). Dan pihak ketiga yang terlibat hanya bisa
 * ditelusuri lewat RoPA — sistem yang belum pernah ditautkan ke RoPA tidak
 * menghasilkan dugaan apa pun.
 *
 * Yang dikunci di sini:
 *   1. hanya sistem yang SELESAI dipindai yang bisa dipilih;
 *   2. hanya tabel yang benar-benar punya kolom PII yang ditawarkan;
 *   3. tipe data & kategori PDP DITURUNKAN dari hasil pindai kita sendiri —
 *      klien cuma mengirim nama tabel, tidak pernah nama kolom;
 *   4. sistem milik tenant lain tidak bisa disisipkan lewat badan permintaan;
 *   5. pihak ketiga terdampak ditelusuri dua arah: lewat RoPA yang memakai
 *      sistem itu, DAN lewat pihak ketiga yang memegang sistemnya langsung.
 */
class BreachDataDiscoveryTest extends TestCase
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

    private function sistem(string $nama, string $status = 'done', ?array $tables = null): InformationSystem
    {
        return InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => $nama,
            'scanning_status' => $status,
            'scan_results' => ['tables' => $tables ?? [
                [
                    'name' => 'users',
                    'row_count' => 1200,
                    'columns' => [
                        ['name' => 'id', 'pii_detected' => false, 'applied_status' => 'not_pii'],
                        ['name' => 'email', 'pii_detected' => true, 'applied_status' => 'applied_pribadi'],
                        // Kolom disamarkan: nama asli tak bermakna, alias bermakna.
                        ['name' => 'A1', 'alias' => 'NIK', 'pii_detected' => true, 'applied_status' => 'applied_sensitive'],
                        // Dugaan pemindai yang BELUM ditinjau — harus diabaikan.
                        ['name' => 'catatan', 'pii_detected' => true, 'applied_status' => 'pending'],
                    ],
                ],
                [
                    'name' => 'audit_trail',
                    'row_count' => 90,
                    'columns' => [
                        ['name' => 'action', 'pii_detected' => false],
                    ],
                ],
            ]],
        ]);
    }

    private function breach(): BreachIncident
    {
        return BreachIncident::create([
            'org_id' => $this->org->id,
            'incident_code' => 'BRC-2026-900',
            'title' => 'Akses tidak sah',
            'severity' => 'high',
        ]);
    }

    public function test_hanya_sistem_yang_selesai_dipindai_yang_ditawarkan(): void
    {
        $this->sistem('CRM Utama', 'done');
        $this->sistem('Gudang Data', 'in_progress');
        $this->sistem('Aplikasi Lama', 'not_started');

        $res = $this->getJson('/api/breach/sistem-terpindai')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('CRM Utama', $res->json('data.0.name'));
        $this->assertSame(1, $res->json('data.0.jumlah_tabel_ber_pii'), 'audit_trail tanpa PII tidak dihitung');
    }

    public function test_hanya_tabel_ber_pii_yang_ditawarkan_dan_alias_dipakai(): void
    {
        $sistem = $this->sistem('CRM Utama');

        $res = $this->getJson("/api/breach/sistem/{$sistem->id}/tabel")->assertOk();

        $tabel = $res->json('data.tabel');
        $this->assertCount(1, $tabel, 'tabel tanpa kolom PII tidak perlu ditawarkan');
        $this->assertSame('users', $tabel[0]['nama']);

        $nama = array_column($tabel[0]['kolom_pii'], 'nama');
        $this->assertSame(['email', 'NIK'], $nama, 'kolom tersamar ditampilkan memakai aliasnya');
        $this->assertSame('A1', $tabel[0]['kolom_pii'][1]['kolom_asli']);
        $this->assertNotContains('catatan', $nama, 'dugaan pemindai yang belum ditinjau tidak boleh ikut');
    }

    public function test_dugaan_mentah_pemindai_tidak_dipakai(): void
    {
        // Satu-satunya kolom ber-PII menurut pemindai, tapi keputusannya belum
        // ditetapkan. Tabelnya tidak boleh ditawarkan sama sekali.
        $sistem = $this->sistem('CRM Utama', 'done', [[
            'name' => 'draft',
            'columns' => [
                ['name' => 'nik', 'pii_detected' => true, 'applied_status' => 'pending'],
                ['name' => 'ktp', 'pii_detected' => true, 'applied_status' => 'rejected'],
            ],
        ]]);

        $res = $this->getJson("/api/breach/sistem/{$sistem->id}/tabel")->assertOk();

        $this->assertSame([], $res->json('data.tabel'),
            'pii_detected hanyalah dugaan — laporan insiden resmi tidak boleh diisi tebakan yang belum ditinjau');
    }

    public function test_sistem_belum_dipindai_ditolak(): void
    {
        $sistem = $this->sistem('Gudang Data', 'in_progress');

        $this->getJson("/api/breach/sistem/{$sistem->id}/tabel")->assertStatus(422);
    }

    public function test_tipe_data_dan_kategori_diturunkan_dari_hasil_pindai(): void
    {
        $sistem = $this->sistem('CRM Utama');
        $breach = $this->breach();

        $this->putJson("/api/breach/{$breach->id}/sistem-terdampak", [
            'sistem' => [['information_system_id' => $sistem->id, 'tables' => ['users']]],
        ])->assertOk();

        $sesudah = $breach->fresh();
        // String dipisah koma — bentuk yang sama dengan seluruh penulis lain,
        // karena UI insiden memanggil `.split(',')` atasnya.
        $this->assertSame('email, NIK', $sesudah->affected_data_types);
        $this->assertSame(['umum', 'spesifik'], $sesudah->affected_data_categories);
        $this->assertSame('CRM Utama', $sesudah->affected_systems[0]['system_name']);
    }

    public function test_klien_tidak_bisa_mengarang_kolom_atau_tabel(): void
    {
        $sistem = $this->sistem('CRM Utama');
        $breach = $this->breach();

        $this->putJson("/api/breach/{$breach->id}/sistem-terdampak", [
            'sistem' => [[
                'information_system_id' => $sistem->id,
                // 'audit_trail' tidak punya PII, 'rekening' tidak ada sama sekali.
                'tables' => ['users', 'audit_trail', 'rekening'],
                // Kiriman ini harus diabaikan sepenuhnya.
                'kolom_pii' => [['nama' => 'saldo', 'kategori_pdp' => 'spesifik']],
            ]],
        ])->assertOk();

        $sesudah = $breach->fresh();
        $this->assertSame('email, NIK', $sesudah->affected_data_types, 'kolom hanya boleh dari hasil pindai kita sendiri');
        $this->assertCount(1, $sesudah->affected_systems[0]['tables'], 'tabel tanpa PII / tidak dikenal diabaikan');
    }

    public function test_sistem_tenant_lain_diabaikan(): void
    {
        $lain = Organization::factory()->create();
        $sistemLain = InformationSystem::create([
            'org_id' => $lain->id,
            'name' => 'Milik Tetangga',
            'scanning_status' => 'done',
            // Kolomnya sengaja LOLOS seluruh saringan PII, supaya yang diuji
            // benar-benar penyaringan org — bukan kebetulan tersaring hal lain.
            'scan_results' => ['tables' => [[
                'name' => 'users',
                'columns' => [['name' => 'nik', 'pii_detected' => true, 'applied_status' => 'applied_sensitive']],
            ]]],
        ]);
        $breach = $this->breach();

        $this->putJson("/api/breach/{$breach->id}/sistem-terdampak", [
            'sistem' => [['information_system_id' => $sistemLain->id, 'tables' => ['users']]],
        ])->assertOk();

        $sesudah = $breach->fresh();
        $this->assertSame([], $sesudah->affected_systems);
        $this->assertSame('', $sesudah->affected_data_types);
    }

    public function test_pihak_ketiga_ditelusuri_dari_sistem_lewat_dua_jalur(): void
    {
        $sistem = $this->sistem('CRM Utama');
        $breach = $this->breach();

        // Jalur langsung: SaaS yang memegang sistemnya.
        $saas = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data']);
        DB::table('information_system_vendor')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $this->org->id,
            'information_system_id' => $sistem->id,
            'vendor_id' => $saas->id,
            'role' => Vendor::ROLE_PROCESSOR,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Jalur RoPA: kegiatan yang memakai sistem itu melibatkan pihak ketiga lain.
        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-500',
            'processing_activity' => 'Penagihan',
        ]);
        // Pivot lama memakai primary key komposit — tidak ada kolom `id`.
        DB::table('information_system_ropa')->insert([
            'org_id' => $this->org->id,
            'information_system_id' => $sistem->id,
            'ropa_id' => $ropa->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $penagih = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Tagih Cepat']);
        DB::table('ropa_vendor')->insert([
            'org_id' => $this->org->id,
            'ropa_id' => $ropa->id,
            'vendor_id' => $penagih->id,
            'role' => Vendor::ROLE_SUB_PROCESSOR,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/breach/{$breach->id}/sistem-terdampak", [
            'sistem' => [['information_system_id' => $sistem->id, 'tables' => ['users']]],
        ])->assertOk();

        $res = $this->getJson("/api/breach/{$breach->id}/pihak-ketiga")->assertOk();

        $this->assertSame(1, $res->json('data.sistem_terdampak'));
        $nama = array_column($res->json('data.dugaan'), 'name');
        sort($nama);
        $this->assertSame(['PT Awan Data', 'PT Tagih Cepat'], $nama);

        $jenis = [];
        foreach ($res->json('data.dugaan') as $d) {
            foreach ($d['alasan'] as $a) {
                $jenis[] = $a['jenis'];
            }
        }
        sort($jenis);
        $this->assertSame(['sistem_langsung', 'sistem_ropa'], $jenis);
    }
}
