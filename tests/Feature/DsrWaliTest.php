<?php

namespace Tests\Feature;

use App\Exceptions\BuktiWaliBelumDiterima;
use App\Models\AuditLog;
use App\Models\ConsentSubject;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DSR oleh wali / pendamping — PP 33/2026 Pasal 38 ayat (5)–(7), Pasal 39 ayat (5).
 *
 * Yang dijaga:
 *   - jalur DIRI SENDIRI tidak ditanyai apa pun dan tidak butuh bukti;
 *   - wali tanpa kewenangan terverifikasi → bukti MENUNGGU; dengan kewenangan
 *     yang cocok (surel wali × subjek) → OTOMATIS;
 *   - hak yang MERUSAK oleh wali terkunci sampai bukti diterima — di semua
 *     pintu, termasuk universal CRUD; mengganti jenis pemohon bersamaan dengan
 *     status tidak melepas gerbang;
 *   - kolom bukti tidak bisa diubah lewat payload universal CRUD.
 */
class DsrWaliTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** Dinamai `aplikasi`, bukan `app` — `$app` sudah dipakai TestCase Laravel. */
    private DsrApp $aplikasi;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->aplikasi = DsrApp::create([
            'org_id' => $this->org->id,
            'name' => 'Portal Nasabah',
            'app_code' => 'portal-'.substr(uniqid(), -6),
        ]);
    }

    // ───────────────────────── bantu ─────────────────────────

    private function ajukan(array $tambahan = []): TestResponse
    {
        return $this->postJson('/api/public/dsr/submit/'.$this->aplikasi->embed_token, array_merge([
            'request_type' => 'deletion',
            'requester_name' => 'Siti R.',
            'requester_email' => 'siti@contoh.id',
        ], $tambahan));
    }

    private function sebagaiWali(array $tambahan = []): array
    {
        return array_merge([
            'requester_type' => DsrRequest::PEMOHON_WALI,
            'requester_relation' => 'orang_tua',
            'subject_class' => KelasSubjek::ANAK,
            'subject_identifier' => 'anak@contoh.id',
        ], $tambahan);
    }

    private function pengguna(array $izin = ['dsr:read', 'dsr:write']): User
    {
        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => 'peran-'.uniqid(),
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
        ]);
    }

    /** Kewenangan wali yang sudah terverifikasi di modul consent — untuk bukti otomatis. */
    private function kewenanganTerverifikasi(string $kontakWali, string $penandaSubjek): GuardianConsent
    {
        $s = ConsentSubject::temukanAtauBuat($this->org->id, $penandaSubjek, ['subject_class' => KelasSubjek::ANAK]);
        $w = Guardian::temukanAtauBuat($this->org->id, $kontakWali, ['name' => 'Siti R.', 'relationship' => 'orang_tua']);

        return GuardianConsent::create([
            'org_id' => $this->org->id,
            'consent_subject_id' => $s->id,
            'guardian_id' => $w->id,
            'verified_at' => now()->subMonth(),
            'verification_method_code' => 'otp_email',
            'verification_driver' => 'otp',
            'verification_confidence' => 'rendah',
        ]);
    }

    private function dsrTerakhir(): DsrRequest
    {
        return DsrRequest::withoutGlobalScope('org')->where('org_id', $this->org->id)->latest('created_at')->firstOrFail();
    }

    // ───────────────────────── pengajuan ─────────────────────────

    #[Test]
    public function diri_sendiri_tanpa_pertanyaan_tambahan_dan_tanpa_bukti(): void
    {
        $this->ajukan()->assertStatus(202);

        $dsr = $this->dsrTerakhir();
        $this->assertSame(DsrRequest::PEMOHON_SUBJEK, $dsr->requester_type);
        $this->assertSame(DsrRequest::BUKTI_TIDAK_PERLU, $dsr->guardian_proof_status);
        $this->assertNull($dsr->guardian_consent_id);
        $this->assertFalse($dsr->buktiWaliMenghalangi());
    }

    #[Test]
    public function wali_tanpa_kewenangan_terverifikasi_menunggu_bukti(): void
    {
        $this->ajukan($this->sebagaiWali())->assertStatus(202);

        $dsr = $this->dsrTerakhir();
        $this->assertSame(DsrRequest::PEMOHON_WALI, $dsr->requester_type);
        $this->assertSame('orang_tua', $dsr->requester_relation);
        $this->assertSame(KelasSubjek::ANAK, $dsr->subject_class);
        $this->assertSame('anak@contoh.id', $dsr->subject_identifier);
        $this->assertNotNull($dsr->subject_identifier_hash);
        $this->assertSame(DsrRequest::BUKTI_MENUNGGU, $dsr->guardian_proof_status);
        $this->assertNull($dsr->guardian_consent_id);
        $this->assertTrue($dsr->buktiWaliMenghalangi());
    }

    #[Test]
    public function wali_dengan_kewenangan_terverifikasi_yang_cocok_otomatis_diterima(): void
    {
        $kw = $this->kewenanganTerverifikasi('siti@contoh.id', 'anak@contoh.id');

        // Ejaan berbeda — normalisasi KunciPencarian yang menyamakannya.
        $this->ajukan($this->sebagaiWali(['subject_identifier' => ' Anak@Contoh.ID ']))->assertStatus(202);

        $dsr = $this->dsrTerakhir();
        $this->assertSame(DsrRequest::BUKTI_OTOMATIS, $dsr->guardian_proof_status);
        $this->assertSame($kw->id, $dsr->guardian_consent_id);
        $this->assertNotNull($dsr->guardian_proof_verified_at);
        $this->assertFalse($dsr->buktiWaliMenghalangi());
    }

    #[Test]
    public function kewenangan_yang_dicabut_atau_untuk_subjek_lain_tidak_dihitung(): void
    {
        $this->kewenanganTerverifikasi('siti@contoh.id', 'lain@contoh.id');
        $dicabut = $this->kewenanganTerverifikasi('siti@contoh.id', 'anak@contoh.id');
        $dicabut->cabut('manual');

        $this->ajukan($this->sebagaiWali())->assertStatus(202);

        $this->assertSame(DsrRequest::BUKTI_MENUNGGU, $this->dsrTerakhir()->guardian_proof_status);
    }

    #[Test]
    public function wali_wajib_menyebut_subjek_yang_diwakili(): void
    {
        $this->ajukan($this->sebagaiWali(['subject_identifier' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_identifier']);

        $this->ajukan(['requester_type' => 'penerjemah'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requester_type']);
    }

    // ───────────────────────── gerbang ─────────────────────────

    #[Test]
    public function hak_merusak_oleh_wali_terkunci_sampai_bukti_diterima(): void
    {
        $this->ajukan($this->sebagaiWali())->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        Sanctum::actingAs($this->pengguna());

        // Universal CRUD — pintu yang paling umum dipakai DPO.
        $this->putJson('/api/m/dsr/'.$dsr->id, ['status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('code', BuktiWaliBelumDiterima::KODE);
        $this->assertSame('pending_verification', $dsr->fresh()->status);

        // DPO memutuskan bukti — alasan wajib.
        $this->postJson('/api/dsr/'.$dsr->id.'/guardian-proof', [
            'decision' => DsrRequest::BUKTI_DITERIMA,
            'reason' => 'Kartu keluarga diperlihatkan di cabang, nama wali dan anak cocok.',
        ])->assertOk();

        $dsr->refresh();
        $this->assertSame(DsrRequest::BUKTI_DITERIMA, $dsr->guardian_proof_status);
        $this->assertNotNull($dsr->guardian_proof_verified_by);
        $this->assertSame(1, AuditLog::where('action', 'dsr.guardian_proof')->count());

        $this->putJson('/api/m/dsr/'.$dsr->id, ['status' => 'in_progress'])->assertSuccessful();
        $this->assertSame('in_progress', $dsr->fresh()->status);
    }

    #[Test]
    public function bukti_yang_ditolak_tetap_mengunci(): void
    {
        $this->ajukan($this->sebagaiWali())->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        Sanctum::actingAs($this->pengguna());

        $this->postJson('/api/dsr/'.$dsr->id.'/guardian-proof', [
            'decision' => DsrRequest::BUKTI_DITOLAK,
            'reason' => 'Nama pada dokumen tidak cocok dengan pemohon.',
        ])->assertOk();

        $this->putJson('/api/m/dsr/'.$dsr->id, ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('code', BuktiWaliBelumDiterima::KODE);
    }

    #[Test]
    public function hak_akses_oleh_wali_tidak_terkunci_otomatis_tapi_buktinya_terlihat(): void
    {
        // Garis Paradoks Wali: longgar untuk yang aman — DPO yang memutuskan
        // sebelum data dikirim, dan bukti yang menunggu terlihat di layarnya.
        $this->ajukan($this->sebagaiWali(['request_type' => 'access']))->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        $this->assertSame(DsrRequest::BUKTI_MENUNGGU, $dsr->guardian_proof_status);

        Sanctum::actingAs($this->pengguna());
        $this->putJson('/api/m/dsr/'.$dsr->id, ['status' => 'in_progress'])->assertSuccessful();
        $this->assertSame('in_progress', $dsr->fresh()->status);
    }

    #[Test]
    public function pendamping_tidak_butuh_bukti_dan_tidak_terkunci(): void
    {
        // Pendamping BUKAN pengambil keputusan — subjeknya sendiri yang
        // mengajukan; menuntut bukti darinya menghambat orang yang berhak.
        $this->ajukan([
            'requester_type' => DsrRequest::PEMOHON_PENDAMPING,
            'requester_relation' => 'pendamping',
            'subject_class' => KelasSubjek::DISABILITAS,
        ])->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        $this->assertSame(DsrRequest::BUKTI_TIDAK_PERLU, $dsr->guardian_proof_status);

        Sanctum::actingAs($this->pengguna());
        $this->putJson('/api/m/dsr/'.$dsr->id, ['status' => 'in_progress'])->assertSuccessful();
    }

    #[Test]
    public function mengganti_jenis_pemohon_bersamaan_dengan_status_tidak_melepas_gerbang(): void
    {
        $this->ajukan($this->sebagaiWali())->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        Sanctum::actingAs($this->pengguna());

        $this->putJson('/api/m/dsr/'.$dsr->id, ['requester_type' => 'subjek', 'status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('code', BuktiWaliBelumDiterima::KODE);

        $dsr->refresh();
        $this->assertSame(DsrRequest::PEMOHON_WALI, $dsr->requester_type);
        $this->assertSame('pending_verification', $dsr->status);
    }

    #[Test]
    public function kolom_bukti_tidak_bisa_diubah_lewat_universal_crud(): void
    {
        $this->ajukan($this->sebagaiWali())->assertStatus(202);
        $dsr = $this->dsrTerakhir();
        Sanctum::actingAs($this->pengguna());

        // Pembaruan biasa tetap berjalan; kolom bukti tidak ikut berubah.
        $this->putJson('/api/m/dsr/'.$dsr->id, [
            'description' => 'Diperbarui oleh DPO.',
            'guardian_proof_status' => DsrRequest::BUKTI_DITERIMA,
            'guardian_consent_id' => (string) Str::uuid(),
        ])->assertSuccessful();

        $dsr->refresh();
        $this->assertSame('Diperbarui oleh DPO.', $dsr->description);
        $this->assertSame(DsrRequest::BUKTI_MENUNGGU, $dsr->guardian_proof_status);
        $this->assertNull($dsr->guardian_consent_id);
    }

    #[Test]
    public function keputusan_bukti_hanya_untuk_permohonan_wali_dan_butuh_alasan(): void
    {
        $this->ajukan()->assertStatus(202);
        $subjek = $this->dsrTerakhir();
        Sanctum::actingAs($this->pengguna());

        $this->postJson('/api/dsr/'.$subjek->id.'/guardian-proof', ['decision' => 'diterima', 'reason' => 'alasan yang cukup panjang'])
            ->assertStatus(422)->assertJsonPath('code', 'BUKAN_PERMOHONAN_WALI');

        $this->ajukan($this->sebagaiWali(['requester_email' => 'lain@contoh.id']))->assertStatus(202);
        // Dua permohonan lahir di detik yang sama — ambil yang wali secara eksplisit.
        $wali = DsrRequest::withoutGlobalScope('org')
            ->where('org_id', $this->org->id)
            ->where('requester_type', DsrRequest::PEMOHON_WALI)
            ->firstOrFail();
        $this->postJson('/api/dsr/'.$wali->id.'/guardian-proof', ['decision' => 'diterima', 'reason' => 'pendek'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson('/api/dsr/'.$wali->id.'/guardian-proof', ['decision' => 'mungkin', 'reason' => 'alasan yang cukup panjang'])
            ->assertStatus(422)->assertJsonValidationErrors(['decision']);
    }
}
