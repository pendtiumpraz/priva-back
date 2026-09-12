<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hak subjek data bagi PENGGUNA KITA SENDIRI (UU PDP Pasal 26, 28 & 43).
 *
 * Ironi yang ditutup di sini: modul DSR dibangun supaya tenant bisa melayani
 * hak subjek datanya, sementara pengguna kita sendiri tidak punya jalur apa pun
 * untuk meminta salinan atau penghapusan datanya.
 *
 * Yang dijaga:
 *   1. salinan data hanya memuat data si pemanggil, tanpa kolom internal dan
 *      tanpa rahasia kredensial;
 *   2. permintaan penghapusan memakai ulang pipeline anonimisasi yang sudah
 *      teruji, bukan jalur pemusnahan kedua;
 *   3. admin terakhir tidak bisa mengunci organisasinya sendiri.
 */
class HakSubjekDataInternalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    private function pengguna(array $override = []): User
    {
        return User::factory()->create(array_merge([
            'org_id' => $this->org->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'role' => 'dpo',
            'is_active' => true,
        ], $override));
    }

    // ---------- Pasal 26: hak akses ----------

    public function test_salinan_data_memuat_data_pribadi_pemanggil(): void
    {
        $user = $this->pengguna();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/user/data-export')->assertOk();

        $res->assertJsonPath('data_pribadi.nama', 'Budi Santoso');
        $res->assertJsonPath('data_pribadi.email', $user->email);
        $res->assertJsonPath('data_pribadi.telepon', '081234567890');
        $res->assertJsonPath('organisasi.nama', 'PT Nusantara Sejahtera');
        $res->assertJsonStructure(['diambil_pada', 'dasar_hukum', 'akun', 'preferensi']);
    }

    public function test_salinan_data_tidak_membocorkan_kredensial(): void
    {
        $user = $this->pengguna();
        Sanctum::actingAs($user);

        $mentah = json_encode($this->getJson('/api/user/data-export')->assertOk()->json());

        // Sandi dan rahasia 2FA bukan "data yang diberikan", dan
        // membocorkannya justru merusak keamanan akun yang sama.
        $this->assertStringNotContainsString($user->password, $mentah);
        $this->assertStringNotContainsString('two_factor_secret', $mentah);
        $this->assertStringNotContainsString('remember_token', $mentah);
    }

    public function test_salinan_data_tidak_memuat_pengguna_lain(): void
    {
        $lain = $this->pengguna(['name' => 'Orang Lain', 'role' => 'admin']);
        $user = $this->pengguna(['name' => 'Budi Santoso']);
        Sanctum::actingAs($user);

        $mentah = json_encode($this->getJson('/api/user/data-export')->assertOk()->json());

        $this->assertStringNotContainsString('Orang Lain', $mentah);
        $this->assertStringNotContainsString($lain->email, $mentah);
    }

    // ---------- Pasal 28 & 43: hak menghapus ----------

    public function test_permintaan_penghapusan_menonaktifkan_akun_dan_mencabut_token(): void
    {
        $this->pengguna(['role' => 'admin', 'name' => 'Admin Lain']); // agar bukan admin terakhir
        $user = $this->pengguna(['role' => 'dpo']);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/erasure-request', ['alasan' => 'Tidak lagi bekerja di sini'])
            ->assertStatus(202)
            ->assertJsonStructure(['message', 'dinonaktifkan_pada']);

        // Soft delete — pemusnahan sebenarnya dikerjakan pipeline anonimisasi
        // setelah masa tenggang, bukan seketika.
        $sesudah = User::withTrashed()->find($user->id);
        $this->assertNotNull($sesudah->deleted_at);
        $this->assertNull($sesudah->anonymized_at, 'pemusnahan menunggu masa tenggang');
        $this->assertSame(0, $user->tokens()->count(), 'sesi aktif harus dicabut');
    }

    public function test_permintaan_penghapusan_tercatat_tanpa_menyalin_identitas(): void
    {
        $this->pengguna(['role' => 'admin']);
        $user = $this->pengguna(['role' => 'dpo', 'name' => 'Budi Santoso']);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/erasure-request', ['alasan' => 'Pindah kerja'])->assertStatus(202);

        $log = AuditLog::where('action', 'erasure_requested')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->record_id);
        $isi = json_encode($log->changes);
        $this->assertStringContainsString('Pindah kerja', $isi);
        // Baris audit hidup lebih lama dari datanya — jangan salin identitas.
        $this->assertStringNotContainsString('Budi Santoso', $isi);
    }

    public function test_pipeline_anonimisasi_menuntaskan_permintaan_penghapusan(): void
    {
        $this->pengguna(['role' => 'admin']);
        $user = $this->pengguna(['role' => 'dpo', 'name' => 'Budi Santoso']);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/erasure-request')->assertStatus(202);

        // Majukan waktu melewati masa tenggang.
        User::withTrashed()->find($user->id)
            ->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $sesudah = User::withTrashed()->find($user->id);
        $this->assertNotNull($sesudah->anonymized_at);
        $this->assertNotSame('Budi Santoso', $sesudah->name);
    }

    public function test_admin_terakhir_tidak_bisa_mengunci_organisasinya(): void
    {
        // Satu-satunya admin aktif di org ini.
        $admin = $this->pengguna(['role' => 'admin', 'name' => 'Admin Tunggal']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/user/erasure-request')->assertStatus(409);

        $this->assertNull(
            User::withTrashed()->find($admin->id)->deleted_at,
            'menghormati satu hak tidak boleh menciptakan insiden ketersediaan',
        );
    }

    public function test_pengguna_biasa_tetap_bisa_menghapus_walau_ada_satu_admin(): void
    {
        $this->pengguna(['role' => 'admin', 'name' => 'Admin Tunggal']);
        $biasa = $this->pengguna(['role' => 'maker']);
        Sanctum::actingAs($biasa);

        $this->postJson('/api/user/erasure-request')->assertStatus(202);

        $this->assertNotNull(User::withTrashed()->find($biasa->id)->deleted_at);
    }
}
