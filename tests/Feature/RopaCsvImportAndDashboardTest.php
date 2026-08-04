<?php

namespace Tests\Feature;

use App\Models\CustomDashboard;
use App\Models\Dpia;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Impor CSV RoPA dan dashboard kustom.
 *
 * Dua sifat yang paling menentukan: impor yang gagal sebagian tidak boleh
 * meninggalkan keadaan separuh jadi, dan dashboard yang dibagikan tidak boleh
 * membocorkan angka dari modul yang izinnya tidak dimiliki penerimanya.
 */
class RopaCsvImportAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO',
            'permissions' => ['ropa:read', 'ropa:write', 'dpia:read', 'dpia:write'],
        ]);
        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'email' => 'dpo'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('ropa.csv', $content);
    }

    // ==================== Impor CSV ====================

    #[Test]
    public function pratinjau_melaporkan_hasil_tanpa_menulis_apa_pun(): void
    {
        Sanctum::actingAs($this->user);

        $csv = "kegiatan pemrosesan,tujuan,divisi,tingkat risiko,penerima\n"
            ."Pembukaan Rekening,Onboarding,Retail,tinggi,\"Biro Kredit; Vendor KYC\"\n"
            ."Penagihan,Collection,Credit,sedang,Agen Penagihan\n";

        $res = $this->postJson('/api/ropa/import/preview', ['file' => $this->csv($csv)])->assertOk();

        $this->assertSame(2, $res->json('data.valid'));
        $this->assertSame(0, $res->json('data.invalid'));
        // Judul kolom berbahasa Indonesia harus dikenali tanpa penyuntingan.
        $this->assertContains('processing_activity', $res->json('data.recognized_columns'));
        $this->assertContains('risk_level', $res->json('data.recognized_columns'));

        // Pratinjau tidak boleh menulis apa pun.
        $this->assertSame(0, Ropa::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function impor_menormalkan_istilah_indonesia_dan_memecah_daftar(): void
    {
        Sanctum::actingAs($this->user);

        $csv = "kegiatan pemrosesan,tingkat risiko,penerima\n"
            ."Pembukaan Rekening,tinggi,\"Biro Kredit; Vendor KYC\"\n";

        $this->postJson('/api/ropa/import/commit', ['file' => $this->csv($csv)])
            ->assertStatus(201)->assertJsonPath('imported', 1);

        $ropa = Ropa::withoutGlobalScope('org')->first();
        $this->assertSame('high', $ropa->risk_level, '"tinggi" harus dinormalkan menjadi high.');
        $this->assertSame(['Biro Kredit', 'Vendor KYC'], $ropa->recipients);
        $this->assertStringStartsWith('ROPA-', $ropa->registration_number);
    }

    #[Test]
    public function baris_bermasalah_dilaporkan_beserta_nomor_barisnya(): void
    {
        Sanctum::actingAs($this->user);

        $csv = "kegiatan pemrosesan,tingkat risiko\n"
            ."Kegiatan Sah,tinggi\n"
            .",tinggi\n"                    // kegiatan kosong
            ."Kegiatan Lain,ngawur\n";      // risiko tidak dikenali

        $res = $this->postJson('/api/ropa/import/preview', ['file' => $this->csv($csv)])->assertOk();

        $this->assertSame(1, $res->json('data.valid'));
        $this->assertSame(2, $res->json('data.invalid'));

        $errors = collect($res->json('data.errors'));
        $this->assertSame([3, 4], $errors->pluck('line')->all(), 'Nomor baris harus menunjuk baris berkas yang sesungguhnya.');
        $this->assertStringContainsString('wajib diisi', $errors->firstWhere('line', 3)['error']);
        $this->assertStringContainsString('tidak dikenali', $errors->firstWhere('line', 4)['error']);
    }

    #[Test]
    public function kegiatan_ganda_dalam_satu_berkas_dilaporkan_bukan_ditelan(): void
    {
        Sanctum::actingAs($this->user);

        $csv = "kegiatan pemrosesan\nPembukaan Rekening\nPembukaan Rekening\n";
        $res = $this->postJson('/api/ropa/import/preview', ['file' => $this->csv($csv)])->assertOk();

        $this->assertSame(1, $res->json('data.valid'));
        $this->assertStringContainsString('ganda di baris 2', $res->json('data.errors.0.error'));
    }

    #[Test]
    public function berkas_tanpa_kolom_kegiatan_ditolak_dengan_penjelasan(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/ropa/import/preview', [
            'file' => $this->csv("kolom_asing,kolom_lain\nisi,isi\n"),
        ])->assertStatus(422);

        $this->assertStringContainsString('kegiatan pemrosesan tidak ditemukan', $res->json('message'));
        $this->assertSame(0, Ropa::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function templat_csv_memuat_judul_kolom_yang_dikenali(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->get('/api/ropa/import/template')->assertOk();
        $content = $res->streamedContent();

        $this->assertStringContainsString('processing_activity', $content);
        $this->assertStringContainsString('risk_level', $content);
    }

    #[Test]
    public function impor_massal_menghasilkan_nomor_yang_tidak_bertabrakan(): void
    {
        Sanctum::actingAs($this->user);

        $rows = "kegiatan pemrosesan\n";
        for ($i = 1; $i <= 25; $i++) {
            $rows .= "Kegiatan ke-{$i}\n";
        }

        $this->postJson('/api/ropa/import/commit', ['file' => $this->csv($rows)])
            ->assertStatus(201)->assertJsonPath('imported', 25);

        $numbers = Ropa::withoutGlobalScope('org')->pluck('registration_number');
        $this->assertCount(25, $numbers->unique(), 'Nomor RoPA hasil impor massal tidak boleh bertabrakan.');
    }

    // ==================== Dashboard kustom ====================

    #[Test]
    public function dashboard_kustom_dibuat_dan_dirender_dengan_data_nyata(): void
    {
        Ropa::create(['org_id' => $this->org->id, 'registration_number' => 'ROPA-2026-001', 'processing_activity' => 'A', 'risk_level' => 'high']);
        Ropa::create(['org_id' => $this->org->id, 'registration_number' => 'ROPA-2026-002', 'processing_activity' => 'B', 'risk_level' => 'low']);

        Sanctum::actingAs($this->user);

        $dashboard = $this->postJson('/api/custom-dashboards', [
            'name' => 'Ringkasan Kepatuhan',
            'module' => 'ropa',
            'widgets' => [
                ['source' => 'ropa.total', 'title' => 'Total RoPA', 'chart' => 'stat'],
                ['source' => 'ropa.by_risk', 'title' => 'Sebaran Risiko', 'chart' => 'pie'],
                ['source' => 'ropa.high_risk', 'chart' => 'stat'],
            ],
        ])->assertStatus(201)->json('data');

        $render = $this->getJson("/api/custom-dashboards/{$dashboard['id']}/render")->assertOk()->json('data');

        $this->assertCount(3, $render['widgets']);
        $this->assertSame(2, $render['widgets'][0]['value']);
        $this->assertSame(1, $render['widgets'][2]['value']);
        $this->assertNotEmpty($render['widgets'][1]['breakdown']);
        $this->assertEmpty($render['withheld']);
    }

    #[Test]
    public function widget_dari_modul_tanpa_izin_ditahan_bukan_ditampilkan(): void
    {
        Dpia::create(['org_id' => $this->org->id, 'registration_number' => 'DPIA-2026-001', 'title' => 'D']);
        Ropa::create(['org_id' => $this->org->id, 'registration_number' => 'ROPA-2026-001', 'processing_activity' => 'A']);

        Sanctum::actingAs($this->user);
        $dashboard = $this->postJson('/api/custom-dashboards', [
            'name' => 'Campuran',
            'module' => 'all',
            'widgets' => [
                ['source' => 'ropa.total'],
                ['source' => 'dpia.total'],
            ],
        ])->assertStatus(201)->json('data');

        // Pengguna lain: hanya berizin RoPA.
        $roleRopaSaja = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Analis RoPA',
            'permissions' => ['ropa:read'],
        ]);
        $ropaOnly = User::create([
            'org_id' => $this->org->id,
            'name' => 'Analis',
            'email' => 'analis'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'tenant_role_id' => $roleRopaSaja->id,
        ]);

        Sanctum::actingAs($ropaOnly);
        $render = $this->getJson("/api/custom-dashboards/{$dashboard['id']}/render")->assertOk()->json('data');

        // Inti pengujian: angka DPIA tidak boleh ikut terbawa hanya karena
        // ditempatkan di dashboard yang dibagikan.
        $this->assertCount(1, $render['widgets']);
        $this->assertSame('ropa.total', $render['widgets'][0]['source']);
        $this->assertCount(1, $render['withheld']);
        $this->assertSame('dpia.total', $render['withheld'][0]['source']);
        $this->assertArrayNotHasKey('value', $render['withheld'][0]);
    }

    #[Test]
    public function daftar_sumber_yang_ditawarkan_mengikuti_izin_pengguna(): void
    {
        $roleRopaSaja = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Analis RoPA',
            'permissions' => ['ropa:read'],
        ]);
        $ropaOnly = User::create([
            'org_id' => $this->org->id,
            'name' => 'Analis',
            'email' => 'analis'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'tenant_role_id' => $roleRopaSaja->id,
        ]);

        Sanctum::actingAs($ropaOnly);
        $sources = array_column($this->getJson('/api/custom-dashboards')->assertOk()->json('meta.available_sources'), 'source');

        $this->assertContains('ropa.total', $sources);
        $this->assertNotContains('dpia.total', $sources);
    }

    #[Test]
    public function dashboard_pribadi_tidak_terlihat_pengguna_lain(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/custom-dashboards', [
            'name' => 'Catatan Pribadi',
            'module' => 'all',
            'owner_user_id' => $this->user->id,
            'widgets' => [['source' => 'ropa.total']],
        ])->assertStatus(201);

        $other = User::create([
            'org_id' => $this->org->id,
            'name' => 'Lain',
            'email' => 'lain'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $this->user->tenant_role_id,
        ]);

        Sanctum::actingAs($other);
        $names = array_column($this->getJson('/api/custom-dashboards')->json('data'), 'name');
        $this->assertNotContains('Catatan Pribadi', $names);
    }

    #[Test]
    public function sumber_data_di_luar_daftar_ditolak_saat_disimpan(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/custom-dashboards', [
            'name' => 'Berbahaya',
            'module' => 'all',
            'widgets' => [['source' => 'users.passwords']],
        ])->assertStatus(422);

        $this->assertSame(0, CustomDashboard::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function dashboard_tidak_terlihat_menolak_render(): void
    {
        Sanctum::actingAs($this->user);
        $dashboard = $this->postJson('/api/custom-dashboards', [
            'name' => 'Pribadi',
            'module' => 'all',
            'owner_user_id' => $this->user->id,
            'widgets' => [['source' => 'ropa.total']],
        ])->assertStatus(201)->json('data');

        $other = User::create([
            'org_id' => $this->org->id,
            'name' => 'Lain',
            'email' => 'lain'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $this->user->tenant_role_id,
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/custom-dashboards/{$dashboard['id']}/render")->assertStatus(403);
    }
}
