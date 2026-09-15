<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\VerificationMethod;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tulang punggung wali — PP 33/2026 Pasal 38 & 39, Fase 1a (diperbaiki 000008).
 *
 * Yang diuji di sini adalah INVARIAN BENTUKNYA, bukan alurnya (alurnya belum
 * ada). Empat hal yang kalau salah akan menggagalkan seluruh fase di atasnya:
 *
 *   - pencarian wali yang tidak pernah cocok;
 *   - tulang punggung yang menggantung di tabel yang tidak pernah ditulis;
 *   - satu anak yang punya dua tanggal dewasa;
 *   - kewenangan yang dianggap berlaku padahal belum diverifikasi.
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

    private function subjek(string $penanda = 'anak@contoh.id', array $atribut = []): ConsentSubject
    {
        return ConsentSubject::temukanAtauBuat($this->org->id, $penanda, $atribut);
    }

    private function wali(string $kontak = 'siti@contoh.id'): Guardian
    {
        return Guardian::temukanAtauBuat($this->org->id, $kontak, [
            'name' => 'Siti R.', 'relationship' => 'orang_tua',
        ]);
    }

    private function kewenangan(ConsentSubject $s, Guardian $w, array $tambahan = []): GuardianConsent
    {
        return GuardianConsent::create(array_merge([
            'org_id' => $this->org->id,
            'consent_subject_id' => $s->id,
            'guardian_id' => $w->id,
        ], $tambahan));
    }

    private function tangkapan(array $tambahan = []): ConsentLog
    {
        return ConsentLog::create(array_merge([
            'org_id' => $this->org->id,
            'collection_id' => $this->titik()->id,
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => ['pemasaran' => true],
        ], $tambahan));
    }

    // ───────────── Ledger mana yang dipakai ─────────────

    #[Test]
    public function tulang_punggung_menggantung_di_tabel_yang_benar_benar_ditulis(): void
    {
        // INI penjaga kekeliruan yang sudah pernah terjadi. Fase 1a memasang
        // kolom Pasal 38 di `consent_records` — tabel yang NOL penulisnya.
        // Penangkapan consent yang sebenarnya masuk ke `consent_logs`, lewat
        // ConsentLogController::capture dan ConsentApiV1Controller::capture.
        //
        // Kalau tertukar lagi, gerbang usia akan memeriksa baris yang tak pernah
        // ada, menyimpulkan "bukan anak", dan MELOLOSKAN tiap penangkapan
        // consent anak — diam-diam, dengan tampilan yang persis seperti bekerja.
        $this->assertTrue(\Schema::hasColumn('consent_logs', 'guardian_consent_id'));
        $this->assertTrue(\Schema::hasColumn('consent_logs', 'subject_class'));

        foreach (['subject_class', 'transition_date', 'transition_state', 'subject_own_channel'] as $kolom) {
            $this->assertFalse(
                \Schema::hasColumn('consent_records', $kolom),
                "consent_records tidak pernah ditulis — kolom {$kolom} di sana tidak akan pernah terisi",
            );
        }
    }

    #[Test]
    public function tangkapan_biasa_tetap_dewasa_dan_tanpa_wali(): void
    {
        // Kontrol positif untuk uji di atas: kolomnya bukan cuma ada, tapi
        // bawaannya membuat seluruh baris lama berperilaku persis seperti
        // sebelum migrasi ini.
        $log = $this->tangkapan()->fresh();

        $this->assertSame(KelasSubjek::DEWASA, $log->subject_class);
        $this->assertNull($log->guardian_consent_id);
    }

    // ───────────────── Pencarian wali ─────────────────

    #[Test]
    public function kontak_terenkripsi_tidak_bisa_dicari_langsung(): void
    {
        // Inilah sebabnya contact_hash ada. Crypt::encryptString memakai IV
        // acak, jadi dua enkripsi atas nilai sama menghasilkan sandi berbeda —
        // `where('contact', ...)` TIDAK PERNAH cocok.
        $this->wali();

        $this->assertNull(
            Guardian::where('org_id', $this->org->id)->where('contact', 'siti@contoh.id')->first(),
            'kalau ini ketemu, enkripsinya deterministik dan seluruh alasan contact_hash gugur',
        );
    }

    #[Test]
    public function wali_yang_sama_tidak_diduplikasi(): void
    {
        $a = $this->wali();
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

        $a = $this->wali();
        $b = Guardian::temukanAtauBuat($lain->id, 'siti@contoh.id', ['name' => 'Siti', 'relationship' => 'orang_tua']);

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function kontak_tersimpan_terenkripsi_di_basis_data(): void
    {
        $wali = $this->wali();

        $mentah = DB::table('guardians')->where('id', $wali->id)->first();

        $this->assertNotSame('siti@contoh.id', $mentah->contact);
        $this->assertNotSame('Siti R.', $mentah->name);
        // Tapi tetap terbaca lewat model.
        $this->assertSame('siti@contoh.id', $wali->fresh()->contact);
    }

    // ───────────────── Subjek yang dilindungi ─────────────────

    #[Test]
    public function penanda_subjek_dinormalkan_sebelum_dicocokkan(): void
    {
        // Sama alasannya dengan wali: tanpa normalisasi, satu anak punya dua
        // baris subjek — dan karenanya dua tanggal peralihan.
        $a = $this->subjek('Anak@Contoh.ID ');
        $b = $this->subjek('anak@contoh.id');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ConsentSubject::where('org_id', $this->org->id)->count());
    }

    #[Test]
    public function penanda_subjek_tersimpan_terenkripsi(): void
    {
        $s = $this->subjek();

        $mentah = DB::table('consent_subjects')->where('id', $s->id)->first();
        $this->assertNotSame('anak@contoh.id', $mentah->subject_label);
        $this->assertSame('anak@contoh.id', $s->fresh()->subject_label);
    }

    #[Test]
    public function tanggal_peralihan_tersimpan_sebagai_tanggal_bukan_tanggal_lahir(): void
    {
        $s = $this->subjek('anak@contoh.id', ['transition_date' => '2029-04-11']);

        $this->assertSame('2029-04-11', $s->fresh()->transition_date->toDateString());
        // Tidak ada kolom tanggal lahir — dan memang tidak boleh ada.
        $this->assertFalse(\Schema::hasColumn('consent_subjects', 'date_of_birth'));
    }

    #[Test]
    public function kanal_milik_subjek_terenkripsi(): void
    {
        // Untuk consent anak, penanda subjeknya biasanya kontak ORANG TUA.
        // Tanpa kolom ini, saat anak itu dewasa sistem tidak punya cara
        // menghubunginya — dan Pasal 38 ayat (8) tidak bisa dijalankan.
        $s = $this->subjek('ibu@contoh.id', ['subject_own_channel' => 'anak@contoh.id']);

        $mentah = DB::table('consent_subjects')->where('id', $s->id)->first();
        $this->assertNotSame('anak@contoh.id', $mentah->subject_own_channel);
        $this->assertSame('anak@contoh.id', $s->fresh()->subject_own_channel);
    }

    #[Test]
    public function satu_anak_dua_wali_tetap_satu_tanggal_peralihan(): void
    {
        // INI alasan tanggal peralihan tidak boleh menempel di kewenangan.
        // Kalau ia menempel di sana, ibu dan ayah masing-masing membawa
        // salinannya, dan antrean peralihan memproses anak yang sama dua kali —
        // atau melewatkannya sama sekali kalau salah satu barisnya dicabut.
        $s = $this->subjek('anak@contoh.id', ['transition_date' => '2029-04-11']);
        $this->kewenangan($s, $this->wali('ibu@contoh.id'), ['verified_at' => now()]);
        $this->kewenangan($s, $this->wali('ayah@contoh.id'), ['verified_at' => now()]);

        $this->assertSame(2, $s->waliBerwenang()->count());
        $this->assertSame(1, ConsentSubject::where('org_id', $this->org->id)->count());

        foreach (['transition_date', 'transition_state', 'subject_own_channel', 'subject_class'] as $kolom) {
            $this->assertFalse(
                \Schema::hasColumn('guardian_consents', $kolom),
                "{$kolom} milik SUBJEK, bukan kewenangan — satu salinan per wali pasti menyimpang",
            );
        }
    }

    #[Test]
    public function siap_beralih_hanya_untuk_anak_yang_tanggalnya_lewat_dan_belum_disentuh(): void
    {
        $lewat = $this->subjek('a@contoh.id', ['transition_date' => now()->subDay()]);
        $this->assertTrue($lewat->siapBeralih());

        $belum = $this->subjek('b@contoh.id', ['transition_date' => now()->addYear()]);
        $this->assertFalse($belum->siapBeralih());

        $tanpa = $this->subjek('c@contoh.id');
        $this->assertFalse($tanpa->siapBeralih());

        // Sudah disentuh antrean — tidak boleh diproses ulang.
        $sudah = $this->subjek('d@contoh.id', [
            'transition_date' => now()->subDay(),
            'transition_state' => KelasSubjek::TRANSISI_MENUNGGU,
        ]);
        $this->assertFalse($sudah->siapBeralih());

        // Bukan anak — tidak beralih.
        $dewasa = $this->subjek('e@contoh.id', [
            'subject_class' => KelasSubjek::DISABILITAS,
            'transition_date' => now()->subDay(),
        ]);
        $this->assertFalse($dewasa->siapBeralih());
    }

    // ───────────────── Kewenangan wali ─────────────────

    #[Test]
    public function kewenangan_belum_terverifikasi_tidak_berlaku(): void
    {
        // Kewenangan yang belum diverifikasi bukan kewenangan — ia baru niat.
        $s = $this->subjek();
        $kw = $this->kewenangan($s, $this->wali());

        $this->assertFalse($kw->masihBerlaku());
        $this->assertSame(0, $s->waliBerwenang()->count());
    }

    #[Test]
    public function kewenangan_belum_terverifikasi_juga_tidak_terhitung_aktif_di_sisi_wali(): void
    {
        // Dua tempat menjawab pertanyaan yang sama — Guardian::kewenanganAktif()
        // dan ConsentSubject::waliBerwenang() — dan keduanya harus menjawab
        // sama. Dulu yang pertama hanya memeriksa pencabutan, sehingga wali yang
        // baru mengisi formulir dan belum menyentuh OTP terbaca berwenang.
        $w = $this->wali();
        $this->kewenangan($this->subjek(), $w);

        $this->assertSame(0, $w->kewenanganAktif()->count());
        $this->assertSame(1, $w->guardianConsents()->count());
    }

    #[Test]
    public function kewenangan_terverifikasi_berlaku(): void
    {
        $s = $this->subjek();
        $w = $this->wali();

        $this->kewenangan($s, $w, [
            'verification_method_code' => 'otp_email',
            'verification_driver' => VerificationMethod::DRIVER_OTP,
            'verification_confidence' => 'rendah',
            'verified_at' => now(),
            'statement_shown' => 'Saya menyetujui pemrosesan data anak saya untuk pemasaran.',
        ]);

        $this->assertSame(1, $s->waliBerwenang()->count());
        $this->assertSame(1, $w->kewenanganAktif()->count());
    }

    #[Test]
    public function kewenangan_yang_dicabut_berhenti_berlaku(): void
    {
        $s = $this->subjek();
        $kw = $this->kewenangan($s, $this->wali(), ['verified_at' => now()]);

        $this->assertTrue($kw->cabut('peralihan_dewasa'));

        $this->assertFalse($kw->fresh()->masihBerlaku());
        $this->assertSame(0, $s->waliBerwenang()->count());
        $this->assertSame('peralihan_dewasa', $kw->fresh()->revoke_reason);
    }

    #[Test]
    public function pencabutan_kedua_ditolak_agar_alasan_pertama_tidak_tertimpa(): void
    {
        $kw = $this->kewenangan($this->subjek(), $this->wali(), ['verified_at' => now()]);

        $kw->cabut('manual');
        $this->assertFalse($kw->cabut('peralihan_dewasa'));
        $this->assertSame('manual', $kw->fresh()->revoke_reason);
    }

    #[Test]
    public function kewenangan_bisa_dicabut_lalu_dipulihkan(): void
    {
        // Hak asuh kembali, atau pencabutan yang keliru. Inilah sebabnya tidak
        // ada indeks unik (subjek, wali): indeks itu akan memaksa baris pertama
        // ditimpa, dan riwayat pencabutan pertama hilang justru pada kasus yang
        // paling perlu ditelusuri.
        $s = $this->subjek();
        $w = $this->wali();

        $lama = $this->kewenangan($s, $w, ['verified_at' => now()->subYear()]);
        $lama->cabut('manual');

        $baru = $this->kewenangan($s, $w, ['verified_at' => now()]);

        $this->assertSame(1, $s->waliBerwenang()->count());
        $this->assertSame(2, $s->guardianConsents()->count());
        $this->assertNotSame($lama->id, $baru->id);
        $this->assertSame('manual', $lama->fresh()->revoke_reason);
    }

    #[Test]
    public function satu_wali_menaungi_banyak_subjek(): void
    {
        // Alasan tabel guardians berdiri sendiri: satu wali bisa menaungi anak
        // MAUPUN penyandang disabilitas.
        $w = $this->wali();
        $this->kewenangan($this->subjek('anak@contoh.id'), $w, ['verified_at' => now()]);
        $this->kewenangan(
            $this->subjek('saudara@contoh.id', ['subject_class' => KelasSubjek::DISABILITAS]),
            $w,
            ['verified_at' => now()],
        );

        $this->assertSame(2, $w->kewenanganAktif()->count());
        $this->assertSame(1, Guardian::where('org_id', $this->org->id)->count());
    }

    // ───────────────── Ledger menunjuk kewenangan ─────────────────

    #[Test]
    public function tangkapan_anak_menunjuk_kewenangan_yang_memayunginya(): void
    {
        $s = $this->subjek();
        $kw = $this->kewenangan($s, $this->wali(), ['verified_at' => now()]);

        $log = $this->tangkapan([
            'guardian_consent_id' => $kw->id,
            'subject_class' => KelasSubjek::ANAK,
        ]);

        $this->assertSame($kw->id, $log->fresh()->guardianConsent->id);
        $this->assertSame(1, $kw->consentLogs()->count());
    }

    #[Test]
    public function potret_kelas_di_ledger_tidak_ikut_berubah_saat_subjek_dewasa(): void
    {
        // Saat diaudit, pertanyaannya "waktu itu ia masih anak?", BUKAN
        // "sekarang ia anak?". Kalau ledger ikut berubah saat peralihan, bukti
        // bahwa consent itu dulu butuh wali lenyap — dan justru itulah yang
        // harus bisa ditunjukkan.
        $s = $this->subjek();
        $kw = $this->kewenangan($s, $this->wali(), ['verified_at' => now()]);
        $log = $this->tangkapan(['guardian_consent_id' => $kw->id, 'subject_class' => KelasSubjek::ANAK]);

        // Peralihan Pasal 38 ayat (8).
        $s->forceFill([
            'subject_class' => KelasSubjek::DEWASA,
            'transition_state' => KelasSubjek::TRANSISI_DIKONFIRMASI,
        ])->save();
        $kw->cabut('peralihan_dewasa');

        $this->assertSame(KelasSubjek::DEWASA, $s->fresh()->subject_class);
        $this->assertSame(KelasSubjek::ANAK, $log->fresh()->subject_class);
        $this->assertSame(0, $s->waliBerwenang()->count());
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
