<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pihak ketiga yang memegang sebuah sistem informasi.
 *
 * Mengisi kaitan yang sebelumnya tidak ada sama sekali di skema:
 * `information_systems.owner_id` adalah foreign key ke `users` (pemilik
 * internal) dan `owner` sekadar teks bebas. Tanpa pivot ini, "SaaS mana yang
 * memegang data di sistem ini" hanya bisa dijawab bila sistemnya kebetulan
 * sudah ditautkan ke sebuah RoPA.
 *
 * Yang dikunci di sini:
 *   1. daftar dikirim UTUH — menyimpan mengganti isinya, bukan menambah;
 *   2. pihak ketiga milik tenant lain tidak bisa disisipkan lewat badan
 *      permintaan;
 *   3. kiriman ganda runtuh menjadi satu (pivotnya unik per sistem+pihak);
 *   4. peran dinormalkan ke sumbu UU PDP yang sama dengan pivot ropa_vendor,
 *      dan peran yang tidak dikenal jatuh ke Prosesor — bukan tersimpan mentah.
 */
class SistemPihakKetigaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private InformationSystem $sistem;

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
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $peran->id,
        ]));

        $this->sistem = InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => 'CRM Utama',
            'scanning_status' => 'done',
        ]);
    }

    private function vendor(string $nama, ?Organization $org = null): Vendor
    {
        return Vendor::create(['org_id' => ($org ?? $this->org)->id, 'name' => $nama]);
    }

    private function simpan(array $pihakKetiga)
    {
        return $this->putJson("/api/data-discovery/{$this->sistem->id}/pihak-ketiga", [
            'pihak_ketiga' => $pihakKetiga,
        ]);
    }

    public function test_awalnya_kosong(): void
    {
        $this->getJson("/api/data-discovery/{$this->sistem->id}/pihak-ketiga")
            ->assertOk()
            ->assertJsonPath('data.pihak_ketiga', []);
    }

    public function test_menyimpan_dan_membaca_kembali_beserta_perannya(): void
    {
        $saas = $this->vendor('PT Awan Data');

        $this->simpan([
            ['vendor_id' => $saas->id, 'role' => 'sub_processor', 'notes' => 'Menyimpan basis data pelanggan'],
        ])->assertOk();

        $res = $this->getJson("/api/data-discovery/{$this->sistem->id}/pihak-ketiga")->assertOk();

        $res->assertJsonPath('data.pihak_ketiga.0.name', 'PT Awan Data');
        $res->assertJsonPath('data.pihak_ketiga.0.role', 'sub_processor');
        $res->assertJsonPath('data.pihak_ketiga.0.role_label', 'Subprosesor');
        $res->assertJsonPath('data.pihak_ketiga.0.notes', 'Menyimpan basis data pelanggan');
    }

    public function test_menyimpan_mengganti_bukan_menambah(): void
    {
        $a = $this->vendor('PT Satu');
        $b = $this->vendor('PT Dua');

        $this->simpan([['vendor_id' => $a->id, 'role' => 'processor']])->assertOk();
        $this->simpan([['vendor_id' => $b->id, 'role' => 'processor']])->assertOk();

        $res = $this->getJson("/api/data-discovery/{$this->sistem->id}/pihak-ketiga")->assertOk();

        $nama = array_column($res->json('data.pihak_ketiga'), 'name');
        $this->assertSame(['PT Dua'], $nama, 'daftar dikirim utuh — yang lama harus tergantikan');
    }

    public function test_pihak_ketiga_tenant_lain_diabaikan(): void
    {
        $lain = Organization::factory()->create();
        $asing = $this->vendor('PT Tetangga', $lain);

        $this->simpan([['vendor_id' => $asing->id, 'role' => 'processor']])->assertOk();

        $this->assertSame(0, DB::table('information_system_vendor')->count(),
            'id dari badan permintaan tidak boleh dipercaya tanpa disaring org');
    }

    public function test_kiriman_ganda_runtuh_jadi_satu(): void
    {
        $saas = $this->vendor('PT Awan Data');

        // Batasan uniknya per (sistem, pihak ketiga) — tanpa dedupe, seluruh
        // penyimpanan akan gagal, bukan cuma barisnya.
        $this->simpan([
            ['vendor_id' => $saas->id, 'role' => 'processor'],
            ['vendor_id' => $saas->id, 'role' => 'controller'],
        ])->assertOk();

        $this->assertSame(1, DB::table('information_system_vendor')->count());
    }

    public function test_peran_dinormalkan_dan_yang_asing_jatuh_ke_prosesor(): void
    {
        $a = $this->vendor('PT Ejaan Lama');
        $b = $this->vendor('PT Peran Ngawur');

        $this->simpan([
            // Ejaan lama yang dikenal alias-nya.
            ['vendor_id' => $a->id, 'role' => 'pengendali'],
            ['vendor_id' => $b->id, 'role' => 'entah-apa'],
        ])->assertOk();

        $peran = DB::table('information_system_vendor')->pluck('role', 'vendor_id');

        $this->assertSame('controller', $peran[$a->id]);
        $this->assertSame('processor', $peran[$b->id], 'peran tak dikenal tidak boleh tersimpan mentah');
    }

    public function test_sistem_tenant_lain_tidak_bisa_disentuh(): void
    {
        $lain = Organization::factory()->create();
        $sistemLain = InformationSystem::create([
            'org_id' => $lain->id,
            'name' => 'Milik Tetangga',
            'scanning_status' => 'done',
        ]);

        $this->putJson("/api/data-discovery/{$sistemLain->id}/pihak-ketiga", ['pihak_ketiga' => []])
            ->assertStatus(404);
    }

    public function test_perubahan_meninggalkan_jejak_audit(): void
    {
        $saas = $this->vendor('PT Awan Data');
        $this->simpan([['vendor_id' => $saas->id, 'role' => 'processor']])->assertOk();

        $this->assertTrue(
            AuditLog::where('module', 'data_discovery')
                ->where('record_id', $this->sistem->id)
                ->where('action', 'third_parties_updated')
                ->exists(),
        );
    }
}
