<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentRecord;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\VerificationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tulang punggung wali — PP 33/2026 Pasal 38 & 39, Fase 1a.
 *
 * Yang diuji di sini adalah INVARIAN BENTUKNYA, bukan alurnya (alurnya belum
 * ada). Tiga hal yang kalau salah akan menggagalkan seluruh fase di atasnya:
 * pencarian wali yang tidak pernah cocok, baris lama yang berubah perilaku,
 * dan kewenangan yang dianggap berlaku padahal belum diverifikasi.
 */
class TulangPunggungWaliTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    private function titik(): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-'.substr(uniqid(), -6),
            'name' => 'Portal Nasabah',
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);
    }

    private function consent(array $tambahan = []): ConsentRecord
    {
        $titik = $this->titik();
        $item = ConsentItem::create([
            'collection_point_id' => $titik->id,
            'title' => 'Pemasaran',
            'specific_purpose' => 'Kirim penawaran',
            'is_required' => false,
        ]);

        return ConsentRecord::create(array_merge([
            'consent_item_id' => $item->id,
            'collection_point_id' => $titik->id,
            'subject_identifier' => 'ibu@contoh.id',
            'is_granted' => true,
            'granted_at' => now(),
        ], $tambahan));
    }

    // ───────────────── Pencarian wali ─────────────────

    #[Test]
    public function kontak_terenkripsi_tidak_bisa_dicari_langsung(): void
    {
        // Inilah sebabnya contact_hash ada. Crypt::encryptString memakai IV
        // acak, jadi dua enkripsi atas nilai sama menghasilkan sandi berbeda —
        // `where('contact', ...)` TIDAK PERNAH cocok.
        Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', [
            'name' => 'Siti R.', 'relationship' => 'orang_tua',
        ]);

        $this->assertNull(
            Guardian::where('org_id', $this->org->id)->where('contact', 'siti@contoh.id')->first(),
            'kalau ini ketemu, enkripsinya deterministik dan seluruh alasan contact_hash gugur',
        );
    }

    #[Test]
    public function wali_yang_sama_tidak_diduplikasi(): void
    {
        $a = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', [
            'name' => 'Siti R.', 'relationship' => 'orang_tua',
        ]);
        $b = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', [
            'name' => 'Siti Rahayu', 'relationship' => 'orang_tua',
        ]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Guardian::where('org_id', $this->org->id)->count());
    }

    #[Test]
    public function kontak_dinormalkan_sebelum_dicocokkan(): void
    {
        // Tanpa normalisasi, sistem membuat dua wali untuk orang yang sama.
        $a = Guardian::temukanAtauBuat($this->org->id, 'Siti@Contoh.ID ', ['name' => 'Siti', 'relationship' => 'orang_tua']);
        $b = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);

        $this->assertSame($a->id, $b->id);
    }

    #[Test]
    public function nomor_telepon_indonesia_disamakan_bentuknya(): void
    {
        $a = Guardian::temukanAtauBuat($this->org->id, '+62 812-3456-7890', ['name' => 'Budi', 'relationship' => 'wali_sah']);
        $b = Guardian::temukanAtauBuat($this->org->id, '081234567890', ['name' => 'Budi', 'relationship' => 'wali_sah']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame('phone', $a->contact_type);
    }

    #[Test]
    public function organisasi_lain_punya_wali_sendiri(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Lain']);

        $a = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);
        $b = Guardian::temukanAtauBuat($lain->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function kontak_tersimpan_terenkripsi_di_basis_data(): void
    {
        $wali = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', [
            'name' => 'Siti R.', 'relationship' => 'orang_tua',
        ]);

        $mentah = DB::table('guardians')->where('id', $wali->id)->first();

        $this->assertNotSame('siti@contoh.id', $mentah->contact);
        $this->assertNotSame('Siti R.', $mentah->name);
        // Tapi tetap terbaca lewat model.
        $this->assertSame('siti@contoh.id', $wali->fresh()->contact);
    }

    // ───────────────── Kelas subjek ─────────────────

    #[Test]
    public function consent_baru_bawaannya_dewasa(): void
    {
        // Seluruh baris lama dan baru tanpa penanda harus berperilaku persis
        // seperti sebelum migrasi ini.
        $this->assertSame(ConsentRecord::KELAS_DEWASA, $this->consent()->fresh()->subject_class);
    }

    #[Test]
    public function tanggal_peralihan_tersimpan_sebagai_tanggal_bukan_tanggal_lahir(): void
    {
        $c = $this->consent([
            'subject_class' => ConsentRecord::KELAS_ANAK,
            'transition_date' => '2029-04-11',
        ]);

        $this->assertSame('2029-04-11', $c->fresh()->transition_date->toDateString());
        // Tidak ada kolom tanggal lahir — dan memang tidak boleh ada.
        $this->assertFalse(\Schema::hasColumn('consent_records', 'date_of_birth'));
    }

    #[Test]
    public function kanal_milik_subjek_terenkripsi(): void
    {
        $c = $this->consent([
            'subject_class' => ConsentRecord::KELAS_ANAK,
            'subject_own_channel' => 'anak@contoh.id',
        ]);

        $mentah = DB::table('consent_records')->where('id', $c->id)->first();
        $this->assertNotSame('anak@contoh.id', $mentah->subject_own_channel);
        $this->assertSame('anak@contoh.id', $c->fresh()->subject_own_channel);
    }

    // ───────────────── Kewenangan wali ─────────────────

    private function kewenangan(ConsentRecord $c, Guardian $w, array $tambahan = []): GuardianConsent
    {
        return GuardianConsent::create(array_merge([
            'org_id' => $this->org->id,
            'consent_record_id' => $c->id,
            'guardian_id' => $w->id,
        ], $tambahan));
    }

    #[Test]
    public function kewenangan_belum_terverifikasi_tidak_berlaku(): void
    {
        // Kewenangan yang belum diverifikasi bukan kewenangan — ia baru niat.
        $c = $this->consent(['subject_class' => ConsentRecord::KELAS_ANAK]);
        $w = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);

        $kw = $this->kewenangan($c, $w);

        $this->assertFalse($kw->masihBerlaku());
        $this->assertSame(0, $c->waliBerwenang()->count());
    }

    #[Test]
    public function kewenangan_terverifikasi_berlaku(): void
    {
        $c = $this->consent(['subject_class' => ConsentRecord::KELAS_ANAK]);
        $w = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);

        $this->kewenangan($c, $w, [
            'verification_method_code' => 'otp_email',
            'verification_driver' => VerificationMethod::DRIVER_OTP,
            'verification_confidence' => 'rendah',
            'verified_at' => now(),
            'statement_shown' => 'Saya menyetujui pemrosesan data anak saya untuk pemasaran.',
        ]);

        $this->assertSame(1, $c->waliBerwenang()->count());
    }

    #[Test]
    public function kewenangan_yang_dicabut_berhenti_berlaku(): void
    {
        $c = $this->consent(['subject_class' => ConsentRecord::KELAS_ANAK]);
        $w = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);
        $kw = $this->kewenangan($c, $w, ['verified_at' => now()]);

        $this->assertTrue($kw->cabut('peralihan_dewasa'));

        $this->assertFalse($kw->fresh()->masihBerlaku());
        $this->assertSame(0, $c->waliBerwenang()->count());
        $this->assertSame('peralihan_dewasa', $kw->fresh()->revoke_reason);
    }

    #[Test]
    public function pencabutan_kedua_ditolak_agar_alasan_pertama_tidak_tertimpa(): void
    {
        $c = $this->consent(['subject_class' => ConsentRecord::KELAS_ANAK]);
        $w = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);
        $kw = $this->kewenangan($c, $w, ['verified_at' => now()]);

        $kw->cabut('manual');
        $this->assertFalse($kw->cabut('peralihan_dewasa'));
        $this->assertSame('manual', $kw->fresh()->revoke_reason);
    }

    #[Test]
    public function satu_wali_menaungi_banyak_persetujuan(): void
    {
        // Alasan tabel guardians berdiri sendiri.
        $w = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);
        $this->kewenangan($this->consent(['subject_class' => ConsentRecord::KELAS_ANAK]), $w, ['verified_at' => now()]);
        $this->kewenangan($this->consent(['subject_class' => ConsentRecord::KELAS_DISABILITAS]), $w, ['verified_at' => now()]);

        $this->assertSame(2, $w->kewenanganAktif()->count());
        $this->assertSame(1, Guardian::where('org_id', $this->org->id)->count());
    }

    // ───────────────── Katalog metode ─────────────────

    #[Test]
    public function metode_bawaan_platform_terlihat_semua_tenant(): void
    {
        // Ini yang akan rusak kalau VerificationMethod memakai BelongsToOrg:
        // global scope menyaring baris org_id NULL dan katalognya kosong.
        VerificationMethod::create([
            'org_id' => null, 'code' => 'otp_email', 'label' => 'OTP Email',
            'driver' => VerificationMethod::DRIVER_OTP, 'confidence' => 'rendah',
        ]);

        $this->assertSame(1, VerificationMethod::untukOrg($this->org->id)->count());
        $this->assertSame(1, VerificationMethod::untukOrg(Organization::factory()->create()->id)->count());
    }

    #[Test]
    public function metode_milik_tenant_tidak_bocor_ke_tenant_lain(): void
    {
        $lain = Organization::factory()->create();
        VerificationMethod::create([
            'org_id' => $this->org->id, 'code' => 'dukcapil', 'label' => 'Dukcapil',
            'driver' => VerificationMethod::DRIVER_DUKCAPIL, 'confidence' => 'tinggi',
        ]);

        $this->assertSame(1, VerificationMethod::untukOrg($this->org->id)->count());
        $this->assertSame(0, VerificationMethod::untukOrg($lain->id)->count());
    }

    #[Test]
    public function kredensial_metode_terenkripsi_dan_tidak_ikut_terserialisasi(): void
    {
        // Kunci Dukcapil milik tenant tidak boleh bocor lewat respons API.
        $m = VerificationMethod::create([
            'org_id' => $this->org->id, 'code' => 'dukcapil', 'label' => 'Dukcapil',
            'driver' => VerificationMethod::DRIVER_DUKCAPIL, 'confidence' => 'tinggi',
            'config' => ['api_key' => 'RAHASIA-123'],
        ]);

        $mentah = DB::table('verification_methods')->where('id', $m->id)->first();
        $this->assertStringNotContainsString('RAHASIA-123', (string) $mentah->config);

        $this->assertSame('RAHASIA-123', $m->fresh()->config['api_key']);
        $this->assertArrayNotHasKey('config', $m->fresh()->toArray());
    }
}
