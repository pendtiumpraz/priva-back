<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Penegakan masa retensi RoPA (UU PDP Pasal 40–42, PP 33 Pasal 80).
 *
 * Sebelum ini `retention_due_date` hanya memicu pengingat: tanggalnya lewat,
 * notifikasi terkirim, lalu tidak pernah terjadi apa-apa. Kewajiban memusnahkan
 * data yang tidak lagi diperlukan tidak pernah ditegakkan.
 *
 * Yang dijaga di sini — dan ini SENGAJA bukan cron yang menghapus sendiri:
 *   1. pemusnahan menuntut persetujuan DPO lebih dulu, dan dua langkah sadar;
 *   2. RoPA yang belum jatuh tempo tidak boleh dimusnahkan;
 *   3. yang dimusnahkan adalah ISI data pribadinya — nomor pendaftaran, jejak
 *      persetujuan, dan tanggal pemusnahan justru harus bertahan sebagai bukti
 *      kepatuhan (Pasal 31);
 *   4. tanggal jatuh tempo terekam SEBELUM wizard dikosongkan, karena hook
 *      saving() akan menurunkannya ulang menjadi null sesudah itu.
 */
class RetensiRopaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $dpo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->dpo = $this->pengguna('dpo');
        Sanctum::actingAs($this->dpo);
    }

    private function pengguna(string $role): User
    {
        $tenantRole = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['ropa:read', 'ropa:write'],
        ]);

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => $tenantRole->id,
        ]);
    }

    /** RoPA dengan tanggal jatuh tempo yang ditetapkan langsung ke kolomnya. */
    private function ropa(?string $jatuhTempo, array $override = []): Ropa
    {
        $ropa = Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'processing_activity' => 'Pembukaan Rekening',
            // Bukan draft: antrean tinjauan hanya memuat pemrosesan berjalan.
            'status' => 'approved',
            'description' => 'Deskripsi berisi rincian internal',
            'data_subjects' => ['Nasabah'],
            'data_categories' => ['NIK', 'Nama'],
            'recipients' => ['Biro Kredit'],
            'security_measures' => 'Enkripsi AES-256',
            'wizard_data' => ['dpo_team' => ['dpo_name' => 'Budi', 'dpo_email' => 'budi@contoh.co.id']],
        ], $override));

        // Hook saving() menurunkan retention_due_date dari wizard; di sini
        // tanggalnya ditetapkan langsung supaya ujinya menguji retensi, bukan
        // penurunan tanggalnya.
        $ropa->forceFill(['retention_due_date' => $jatuhTempo])->saveQuietly();

        return $ropa->fresh();
    }

    public function test_antrean_jatuh_tempo_memisahkan_yang_terlampaui(): void
    {
        $this->ropa(now()->subDays(10)->toDateString(), ['processing_activity' => 'Sudah Lewat']);
        $this->ropa(now()->addDays(5)->toDateString(), ['processing_activity' => 'Segera']);
        $this->ropa(now()->addYear()->toDateString(), ['processing_activity' => 'Masih Lama']);

        $res = $this->getJson('/api/ropa/retensi/jatuh-tempo')->assertOk();

        $res->assertJsonPath('ringkasan.total', 2);
        $res->assertJsonPath('ringkasan.terlampaui', 1);
    }

    public function test_pemusnahan_ditolak_tanpa_persetujuan_dpo(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());

        $this->postJson("/api/ropa/{$ropa->id}/retensi/musnahkan")
            ->assertStatus(422)
            ->assertJsonPath('retention_review_status', null);

        $this->assertNull($ropa->fresh()->retention_destroyed_at);
    }

    public function test_pemusnahan_ditolak_bila_belum_jatuh_tempo(): void
    {
        $ropa = $this->ropa(now()->addYear()->toDateString());

        $this->postJson("/api/ropa/{$ropa->id}/retensi/setujui-pemusnahan")
            ->assertStatus(422);

        $this->assertNull($ropa->fresh()->retention_review_status);
    }

    public function test_hanya_dpo_yang_boleh_memutuskan(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());
        Sanctum::actingAs($this->pengguna('maker'));

        $this->postJson("/api/ropa/{$ropa->id}/retensi/setujui-pemusnahan")->assertStatus(403);
        $this->postJson("/api/ropa/{$ropa->id}/retensi/perpanjang", ['alasan' => 'Masih dibutuhkan audit'])
            ->assertStatus(403);
    }

    public function test_perpanjangan_menuntut_alasan(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());

        $this->postJson("/api/ropa/{$ropa->id}/retensi/perpanjang", [])->assertStatus(422);

        $this->postJson("/api/ropa/{$ropa->id}/retensi/perpanjang", ['alasan' => 'Masih dibutuhkan untuk audit OJK'])
            ->assertOk();

        $sesudah = $ropa->fresh();
        $this->assertSame('extended', $sesudah->retention_review_status);
        $this->assertSame('Masih dibutuhkan untuk audit OJK', $sesudah->retention_review_notes);
        $this->assertNotNull($sesudah->retention_reviewed_at);
    }

    public function test_pemusnahan_menghapus_isi_tapi_mempertahankan_bukti(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());
        $nomor = $ropa->registration_number;
        // Kolom ini tidak di-cast di model — sengaja diperlakukan sebagai string.
        $jatuhTempo = (string) $ropa->retention_due_date;

        $this->postJson("/api/ropa/{$ropa->id}/retensi/setujui-pemusnahan", ['alasan' => 'Masa simpan berakhir'])
            ->assertOk();
        $this->postJson("/api/ropa/{$ropa->id}/retensi/musnahkan")->assertOk();

        $sesudah = Ropa::find($ropa->id);

        // Isi data pribadinya hilang — diperiksa per kolom.
        $this->assertNull($sesudah->data_subjects);
        $this->assertNull($sesudah->data_categories);
        $this->assertNull($sesudah->recipients);
        $this->assertNull($sesudah->description);
        $this->assertNull($sesudah->wizard_data, 'wizard memuat kontak DPO — harus ikut musnah');

        // Buktinya bertahan.
        $this->assertNotNull($sesudah, 'barisnya tidak boleh hilang — catatan itu sendiri kewajiban Pasal 31');
        $this->assertSame($nomor, $sesudah->registration_number);
        $this->assertNotNull($sesudah->retention_destroyed_at);
        $this->assertSame('destroyed', $sesudah->retention_review_status);

        // Inti perkaranya: hook saving() menurunkan ulang `retention_due_date`
        // dari `wizard_data` pada SETIAP penyimpanan — termasuk saat
        // persetujuan — sehingga kolom turunan itu memang berakhir kosong.
        // Justru karena itu buktinya dipatri ke kolom tersendiri saat DPO
        // memutuskan, bukan dibaca ulang saat pemusnahan.
        $this->assertNull($sesudah->retention_due_date, 'kolom turunan wajar ikut kosong');
        $this->assertNotNull(
            $sesudah->retention_due_date_at_destruction,
            'tanggal dasar pemusnahan harus selamat dari hitung ulang hook saving()',
        );
        $this->assertSame($jatuhTempo, $sesudah->retention_due_date_at_destruction->toDateString());
    }

    public function test_pemusnahan_kedua_ditolak(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());

        $this->postJson("/api/ropa/{$ropa->id}/retensi/setujui-pemusnahan")->assertOk();
        $this->postJson("/api/ropa/{$ropa->id}/retensi/musnahkan")->assertOk();
        $this->postJson("/api/ropa/{$ropa->id}/retensi/musnahkan")->assertStatus(422);
    }

    public function test_setiap_keputusan_meninggalkan_jejak_audit(): void
    {
        $ropa = $this->ropa(now()->subDays(10)->toDateString());

        $this->postJson("/api/ropa/{$ropa->id}/retensi/setujui-pemusnahan", ['alasan' => 'Masa simpan berakhir'])->assertOk();
        $this->postJson("/api/ropa/{$ropa->id}/retensi/musnahkan")->assertOk();

        foreach (['retention_destruction_approved', 'retention_destroyed'] as $aksi) {
            $this->assertTrue(
                AuditLog::where('module', 'ropa')->where('record_id', $ropa->id)->where('action', $aksi)->exists(),
                "jejak audit '{$aksi}' harus ada",
            );
        }
    }

    public function test_ropa_tenant_lain_tidak_bisa_dimusnahkan(): void
    {
        $lain = Organization::factory()->create();
        $punyaLain = Ropa::create([
            'org_id' => $lain->id,
            'registration_number' => 'ROPA-2026-900',
            'processing_activity' => 'Milik Tetangga',
        ]);

        $this->postJson("/api/ropa/{$punyaLain->id}/retensi/setujui-pemusnahan")->assertStatus(404);
    }
}
