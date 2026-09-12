<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pemusnahan data pribadi pengguna (UU PDP Pasal 43 & 44).
 *
 * Sebelum ini, menghapus pengguna hanya soft delete — nama, surel, dan telepon
 * bertahan selamanya. Untuk aplikasi yang dijual sebagai alat kepatuhan PDP,
 * itu temuan terhadap diri sendiri.
 *
 * Anonimisasi bersifat SATU ARAH dan merusak data dengan sengaja, jadi yang
 * diuji bukan "perintahnya jalan" melainkan:
 *   1. data pribadinya benar-benar TIDAK ADA lagi — diperiksa per kolom;
 *   2. barisnya beserta `id` tetap ada, supaya jejak audit dan assignee
 *      RoPA/DPIA tidak putus;
 *   3. masa tenggang dihormati di KEDUA arah — yang belum lewat tidak boleh
 *      tersentuh, yang sudah lewat tidak boleh terlewat;
 *   4. idempoten — baris yang sudah dianonimkan tidak diproses ulang;
 *   5. pengguna aktif tidak pernah ikut terkena;
 *   6. pemulihan akun ditolak sesudahnya.
 */
class AnonimisasiPenggunaTest extends TestCase
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
            'email' => 'budi'.uniqid().'@contoh.co.id',
            'phone' => '081234567890',
            'is_active' => true,
        ], $override));
    }

    /** Hapus lalu mundurkan deleted_at, meniru penghapusan beberapa hari lalu. */
    private function hapusSejak(User $user, int $hariLalu): User
    {
        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays($hariLalu)])->saveQuietly();

        return $user->fresh();
    }

    public function test_data_pribadi_benar_benar_dimusnahkan(): void
    {
        $user = $this->pengguna(['name' => 'Budi Santoso', 'phone' => '081234567890']);
        $emailAsli = $user->email;
        $this->hapusSejak($user, 40);

        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $sesudah = User::withTrashed()->find($user->id);

        $this->assertNotNull($sesudah, 'barisnya harus tetap ada — id-nya dirujuk jejak audit');
        $this->assertSame($user->id, $sesudah->id);

        // Diperiksa per kolom: klaim "sudah dimusnahkan" harus bisa dibuktikan,
        // bukan disimpulkan dari satu penanda.
        $this->assertNotSame('Budi Santoso', $sesudah->name);
        $this->assertNotSame($emailAsli, $sesudah->email);
        $this->assertNull($sesudah->phone);
        $this->assertNull($sesudah->avatar_url);
        $this->assertNull($sesudah->position);
        $this->assertNotNull($sesudah->anonymized_at);
        $this->assertFalse((bool) $sesudah->is_active);

        // Surel pengganti tidak boleh bisa dikirimi apa pun.
        $this->assertStringEndsWith('@privasimu.invalid', $sesudah->email);
    }

    public function test_kredensial_ikut_dimusnahkan(): void
    {
        $user = $this->pengguna();
        $sandiLama = $user->password;
        $this->hapusSejak($user, 40);

        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $sesudah = User::withTrashed()->find($user->id);

        $this->assertNotSame($sandiLama, $sesudah->password, 'akun tidak boleh masih bisa dipakai');
        $this->assertNull($sesudah->two_factor_secret);
        $this->assertNull($sesudah->remember_token);
    }

    public function test_masa_tenggang_dihormati_dua_arah(): void
    {
        $baru = $this->hapusSejak($this->pengguna(['name' => 'Baru Dihapus']), 5);
        $lama = $this->hapusSejak($this->pengguna(['name' => 'Lama Dihapus']), 40);

        $this->artisan('users:anonymize-deleted --days=30')->assertSuccessful();

        // Baru dihapus → masih dalam tenggang, pemulihan akun harus tetap mungkin.
        $this->assertNull(User::withTrashed()->find($baru->id)->anonymized_at);
        $this->assertSame('Baru Dihapus', User::withTrashed()->find($baru->id)->name);

        // Sudah lewat tenggang → wajib dimusnahkan, bukan sekadar boleh.
        $this->assertNotNull(User::withTrashed()->find($lama->id)->anonymized_at);
    }

    public function test_pengguna_aktif_tidak_pernah_tersentuh(): void
    {
        $aktif = $this->pengguna(['name' => 'Masih Aktif']);

        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $sesudah = User::find($aktif->id);
        $this->assertSame('Masih Aktif', $sesudah->name);
        $this->assertNull($sesudah->anonymized_at);
        $this->assertTrue((bool) $sesudah->is_active);
    }

    public function test_idempoten_tidak_diproses_dua_kali(): void
    {
        $user = $this->hapusSejak($this->pengguna(), 40);

        $this->artisan('users:anonymize-deleted')->assertSuccessful();
        $pertama = User::withTrashed()->find($user->id)->anonymized_at;

        $this->artisan('users:anonymize-deleted')->assertSuccessful();
        $kedua = User::withTrashed()->find($user->id)->anonymized_at;

        $this->assertEquals($pertama, $kedua, 'baris yang sudah dianonimkan tidak boleh diproses ulang');
        $this->assertSame(
            1,
            AuditLog::where('module', 'users')->where('action', 'anonymized')->count(),
            'jejak audit tidak boleh menggandakan entri',
        );
    }

    public function test_jejak_audit_tidak_memuat_data_yang_dimusnahkan(): void
    {
        $user = $this->pengguna(['name' => 'Budi Santoso']);
        $emailAsli = $user->email;
        $this->hapusSejak($user, 40);

        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $log = AuditLog::where('module', 'users')->where('action', 'anonymized')->first();

        $this->assertNotNull($log);
        // Mencatat nama/surel yang baru saja dimusnahkan akan membatalkan
        // gunanya pemusnahan itu sendiri.
        $isi = json_encode($log->changes);
        $this->assertStringNotContainsString('Budi Santoso', $isi);
        $this->assertStringNotContainsString($emailAsli, $isi);
    }

    public function test_dry_run_tidak_mengubah_apa_pun(): void
    {
        $user = $this->hapusSejak($this->pengguna(['name' => 'Budi Santoso']), 40);

        $this->artisan('users:anonymize-deleted --dry-run')->assertSuccessful();

        $sesudah = User::withTrashed()->find($user->id);
        $this->assertNull($sesudah->anonymized_at);
        $this->assertSame('Budi Santoso', $sesudah->name);
    }

    public function test_pemulihan_ditolak_setelah_dianonimkan(): void
    {
        $user = $this->hapusSejak($this->pengguna(), 40);
        $this->artisan('users:anonymize-deleted')->assertSuccessful();

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['users:read', 'users:write'],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
            'tenant_role_id' => $role->id,
        ]));

        $this->postJson("/api/users/{$user->id}/restore")
            ->assertStatus(410)
            ->assertJsonStructure(['message', 'anonymized_at']);
    }
}
