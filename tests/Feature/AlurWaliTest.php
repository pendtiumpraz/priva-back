<?php

namespace Tests\Feature;

use App\Jobs\FireConsentWebhookJob;
use App\Mail\GuardianVerificationMail;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Services\Consent\GerbangWali;
use App\Services\Consent\LayananWali;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alur wali — PP 33/2026 Pasal 38 & 39, Fase 2.
 *
 * Empat hal yang kalau salah membuat fitur ini berbahaya, bukan sekadar cacat:
 *
 *   - anak tanpa wali LOLOS ke ledger (ledger "yang terbaru menang" akan
 *     membacanya sebagai persetujuan sah);
 *   - membuka tautan = menyetujui (pemindai surel jadi "wali");
 *   - kewenangan tenant lain, subjek lain, atau yang sudah dicabut diterima;
 *   - penyandang disabilitas dipaksa lewat wali.
 */
class AlurWaliTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ConsentCollectionPoint $cp;

    private ConsentItem $item;

    private string $clientKey;

    private string $serverKey;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();

        $this->org = Organization::create([
            'name' => 'Bank Uji',
            'slug' => 'bank-uji-'.Str::random(6),
        ]);

        $this->cp = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-001',
            'name' => 'Formulir Tabungan Pelajar',
            'kind' => ConsentCollectionPoint::KIND_APP,
            'webhook_url' => 'https://penerima.contoh.id/hook',
        ]);

        [$this->clientKey, $this->serverKey] = ConsentCollectionPoint::generateApiKeyPair();
        $this->cp->update([
            'client_key' => $this->clientKey,
            'server_key' => $this->serverKey,
            'auth_methods' => ['widget' => true, 'api_key' => true],
        ]);

        $this->item = ConsentItem::create([
            'collection_point_id' => $this->cp->id,
            'title' => 'Penawaran tabungan pelajar',
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);
    }

    // ───────────────────── Gerbang: jalur widget ─────────────────────

    #[Test]
    public function anak_tanpa_wali_ditolak_dan_tidak_meninggalkan_baris(): void
    {
        $r = $this->tangkap(['subject_class' => 'anak']);

        $r->assertStatus(422)->assertJsonPath('code', GerbangWali::WALI_WAJIB);

        // Yang menentukan bukan 422-nya, tapi ini: ledger "yang terbaru menang"
        // akan membaca baris yang terlanjur masuk sebagai persetujuan sah.
        $this->assertSame(0, ConsentLog::count());
        Queue::assertNotPushed(FireConsentWebhookJob::class);
    }

    #[Test]
    public function anak_tanpa_wali_ditolak_di_jalur_partner_api(): void
    {
        // Pintu masuk yang berbeda tidak boleh punya penjagaan yang berbeda.
        $r = $this->v1('POST', '/api/v1/consent/capture', [
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => true],
            'subject_class' => 'anak',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', GerbangWali::WALI_WAJIB);
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function dewasa_tanpa_penanda_berperilaku_seperti_sebelumnya(): void
    {
        $this->tangkap()->assertStatus(201);

        $log = ConsentLog::first();
        $this->assertSame(KelasSubjek::DEWASA, $log->subject_class);
        $this->assertNull($log->guardian_consent_id);
    }

    #[Test]
    public function penyandang_disabilitas_menyetujui_sendiri(): void
    {
        // Pasal 39: yang dibutuhkan adalah penyajian yang dapat diakses, bukan
        // wali. Memaksa jalur wali berarti mencabut kapasitas hukum orang yang
        // memilikinya.
        $this->tangkap(['subject_class' => 'disabilitas'])->assertStatus(201);

        $log = ConsentLog::first();
        $this->assertSame(KelasSubjek::DISABILITAS, $log->subject_class);
        $this->assertNull($log->guardian_consent_id);
    }

    #[Test]
    public function guardian_mode_mewajibkan_kelas_subjek(): void
    {
        // Sakelar yang dulu tersimpan tanpa mengubah apa pun. Sekarang berarti:
        // titik ini menuntut tiap penangkapan menyatakan kelas subjeknya.
        $this->cp->update(['settings' => ['guardian_mode' => true]]);

        $this->tangkap()->assertStatus(422)->assertJsonPath('code', GerbangWali::KELAS_WAJIB);
        $this->assertSame(0, ConsentLog::count());

        $this->tangkap(['subject_class' => 'dewasa'])->assertStatus(201);
    }

    #[Test]
    public function kelas_subjek_yang_tidak_dikenal_ditolak(): void
    {
        $this->tangkap(['subject_class' => 'remaja'])->assertStatus(422);
        $this->assertSame(0, ConsentLog::count());
    }

    // ───────────────────── Pengajuan ─────────────────────

    #[Test]
    public function pengajuan_membuat_subjek_wali_dan_kewenangan_yang_menunggu(): void
    {
        $r = $this->ajukan(['transition_date' => '2030-01-15']);

        $r->assertStatus(202)
            ->assertJsonPath('status', 'menunggu_wali')
            ->assertJsonStructure(['guardian_consent_id', 'expires_at']);

        $subjek = ConsentSubject::first();
        $this->assertSame(KelasSubjek::ANAK, $subjek->subject_class);
        $this->assertSame('2030-01-15', $subjek->transition_date->toDateString());
        $this->assertSame('anak@contoh.id', $subjek->subject_label);

        $wali = Guardian::first();
        $this->assertSame('siti@contoh.id', $wali->contact);
        $this->assertSame('orang_tua', $wali->relationship);

        $kw = GuardianConsent::first();
        $this->assertNull($kw->verified_at);
        $this->assertNotNull($kw->verification_token_hash);
        $this->assertFalse($kw->tokenKedaluwarsa());

        Mail::assertQueued(GuardianVerificationMail::class, fn ($m) => $m->hasTo('siti@contoh.id'));

        // BELUM ada satu baris pun di ledger.
        $this->assertSame(0, ConsentLog::count());
        Queue::assertNotPushed(FireConsentWebhookJob::class);
    }

    #[Test]
    public function kontak_telepon_belum_didukung_dan_ditolak_terbuka(): void
    {
        // Ditolak SEBELUM transaksi — tidak boleh meninggalkan subjek dan wali
        // setengah jadi tanpa tautan yang pernah terkirim.
        $r = $this->ajukan(['guardian' => [
            'name' => 'Siti R.', 'contact' => '081234567890', 'relationship' => 'orang_tua',
        ]]);

        $r->assertStatus(422)->assertJsonPath('code', LayananWali::KANAL_BELUM_DIDUKUNG);

        $this->assertSame(0, ConsentSubject::count());
        $this->assertSame(0, Guardian::count());
        $this->assertSame(0, GuardianConsent::count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function tanggal_peralihan_harus_di_masa_depan(): void
    {
        $this->ajukan(['transition_date' => now()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transition_date']);
    }

    #[Test]
    public function pengajuan_ulang_terlalu_cepat_ditolak_jujur_bukan_diam(): void
    {
        $this->ajukan()->assertStatus(202);
        $this->ajukan()->assertStatus(429)->assertJsonPath('code', LayananWali::TERLALU_CEPAT);

        Mail::assertQueuedCount(1);
        $this->assertSame(1, GuardianConsent::count());
    }

    #[Test]
    public function token_dan_pilihan_yang_menunggu_tidak_pernah_terserialisasi(): void
    {
        $this->ajukan()->assertStatus(202);

        $kw = GuardianConsent::first();
        $this->assertArrayNotHasKey('verification_token_hash', $kw->toArray());
        $this->assertArrayNotHasKey('pending_capture', $kw->toArray());

        $this->getJson($this->tautanDariSurel())
            ->assertOk()
            ->assertJsonMissingPath('verification_token_hash')
            ->assertJsonMissingPath('pending_capture');
    }

    // ───────────────────── Melihat ≠ menyetujui ─────────────────────

    #[Test]
    public function membuka_tautan_hanya_menampilkan_dan_tidak_menyetujui(): void
    {
        $this->ajukan()->assertStatus(202);
        $url = $this->tautanDariSurel();

        $r = $this->getJson($url);

        $r->assertOk()
            ->assertJsonPath('already_verified', false)
            ->assertJsonPath('guardian_name', 'Siti R.')
            ->assertJsonPath('subject_label', 'anak@contoh.id')
            ->assertJsonPath('purposes.0', 'Penawaran tabungan pelajar');
        $this->assertStringContainsString('Penawaran tabungan pelajar', $r->json('statement'));

        // Pemindai tautan di server surel membuka tautan sebelum manusianya.
        $this->assertNull(GuardianConsent::first()->verified_at);
        $this->assertSame(0, ConsentLog::count());

        // Dan tautannya masih hidup untuk manusianya nanti.
        $this->getJson($url)->assertOk();
    }

    #[Test]
    public function wali_menyetujui_lewat_tautan_dan_barulah_ledger_ditulis(): void
    {
        $this->ajukan()->assertStatus(202);
        $url = $this->tautanDariSurel();

        $r = $this->postJson($url);

        $r->assertOk()->assertJsonPath('subject_class', 'anak');

        $kw = GuardianConsent::first();
        $this->assertNotNull($kw->verified_at);
        $this->assertSame('otp_email', $kw->verification_method_code);
        $this->assertSame('rendah', $kw->verification_confidence);
        $this->assertStringContainsString('Siti R.', (string) $kw->statement_shown);
        $this->assertStringContainsString('Penawaran tabungan pelajar', (string) $kw->statement_shown);
        // Sekali pakai.
        $this->assertNull($kw->verification_token_hash);
        $this->assertNull($kw->pending_capture);

        $log = ConsentLog::first();
        $this->assertNotNull($log);
        $this->assertSame($kw->id, $log->guardian_consent_id);
        $this->assertSame(KelasSubjek::ANAK, $log->subject_class);
        $this->assertSame('anak@contoh.id', $log->user_identifier);
        $this->assertSame($this->cp->id, $log->collection_id);
        $this->assertTrue((bool) ($log->consented_items[$this->item->id] ?? false));
        $this->assertSame($r->json('log_id'), $log->id);

        Queue::assertPushed(FireConsentWebhookJob::class, fn ($j) => ($j->payload['source'] ?? null) === 'guardian_verify'
            && ($j->payload['subject_class'] ?? null) === 'anak'
            && ($j->payload['guardian_consent_id'] ?? null) === $kw->id);

        $this->assertSame(1, AuditLog::where('action', 'guardian_consent.confirm')->count());

        // Tautan yang sudah dipakai mati.
        $this->postJson($url)->assertStatus(404)->assertJsonPath('code', LayananWali::TOKEN_TIDAK_DIKENAL);
        $this->assertSame(1, ConsentLog::count());
    }

    #[Test]
    public function tautan_kedaluwarsa_ditolak_tanpa_menulis_apa_pun(): void
    {
        $this->ajukan()->assertStatus(202);
        $url = $this->tautanDariSurel();

        $this->travel(LayananWali::MASA_BERLAKU_JAM + 1)->hours();

        $this->getJson($url)->assertStatus(410)->assertJsonPath('code', LayananWali::TOKEN_KEDALUWARSA);
        $this->postJson($url)->assertStatus(410)->assertJsonPath('code', LayananWali::TOKEN_KEDALUWARSA);

        $this->assertNull(GuardianConsent::first()->verified_at);
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function halaman_html_untuk_peramban_wali(): void
    {
        $this->ajukan()->assertStatus(202);
        $url = $this->tautanDariSurel();

        $this->get($url, ['Accept' => 'text/html'])
            ->assertOk()
            ->assertSee('Saya menyetujui')
            ->assertSee('Penawaran tabungan pelajar');

        $this->assertSame(0, ConsentLog::count());

        $this->post($url, [], ['Accept' => 'text/html'])
            ->assertOk()
            ->assertSee('Persetujuan tercatat');

        $this->assertSame(1, ConsentLog::count());
    }

    // ───────────────────── Kewenangan yang sudah berdiri ─────────────────────

    #[Test]
    public function tangkapan_berikutnya_memakai_kewenangan_yang_sama_tanpa_verifikasi_ulang(): void
    {
        $this->ajukan()->assertStatus(202);
        $this->postJson($this->tautanDariSurel())->assertOk();
        $kw = GuardianConsent::first();

        // Aplikasi tenant (mis. orang tua login di aplikasi mereka) mencatat
        // perubahan preferensi lewat Partner API dengan kewenangan yang ada.
        $r = $this->v1('POST', '/api/v1/consent/capture', [
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => false],
            'subject_class' => 'anak',
            'guardian_consent_id' => $kw->id,
        ]);

        $r->assertStatus(201);
        $this->assertSame(2, ConsentLog::count());
        $this->assertSame($kw->id, ConsentLog::latest('created_at')->first()->guardian_consent_id);
        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function pengajuan_ulang_memakai_kewenangan_yang_sama_tapi_tetap_butuh_tindakan_wali(): void
    {
        // Kewenangan yang berdiri BUKAN persetujuan yang berdiri. Pasangan
        // (subjek, wali) yang sama dipakai ulang, tapi wali tetap harus
        // menyetujui pilihan yang baru.
        $this->ajukan()->assertStatus(202);
        $this->postJson($this->tautanDariSurel())->assertOk();
        $pertama = GuardianConsent::first();

        $this->travel(LayananWali::JEDA_KIRIM_ULANG_DETIK + 1)->seconds();
        $this->ajukan(['consented_items' => [$this->item->id => false]])->assertStatus(202);

        $this->assertSame(1, GuardianConsent::count());
        $this->assertSame(1, ConsentLog::count(), 'pilihan baru belum boleh masuk ledger');

        $this->postJson($this->tautanDariSurel(2))->assertOk();

        $this->assertSame(2, ConsentLog::count());
        $this->assertSame($pertama->verified_at->timestamp, GuardianConsent::first()->verified_at->timestamp, 'verified_at asli dipertahankan');
    }

    #[Test]
    public function kewenangan_tenant_lain_ditolak_dengan_pesan_yang_sama(): void
    {
        $lain = Organization::create(['name' => 'PT Lain', 'slug' => 'pt-lain-'.Str::random(6)]);
        $kwLain = $this->kewenanganSah('anak@contoh.id', $lain);

        $r = $this->v1('POST', '/api/v1/consent/capture', [
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => true],
            'subject_class' => 'anak',
            'guardian_consent_id' => $kwLain->id,
        ]);

        $r->assertStatus(422)
            ->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH)
            ->assertJsonPath('error', 'Kewenangan wali tidak ditemukan.');
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function kewenangan_untuk_subjek_lain_ditolak(): void
    {
        // Satu kewenangan atas anak A tidak boleh dipakai menangkap consent
        // atas anak B hanya dengan menyalin id-nya.
        $kw = $this->kewenanganSah('anak@contoh.id');

        $this->tangkap([
            'user_identifier' => 'adik@contoh.id',
            'subject_class' => 'anak',
            'guardian_consent_id' => $kw->id,
        ])->assertStatus(422)->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH);

        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function kewenangan_yang_dicabut_ditolak(): void
    {
        $kw = $this->kewenanganSah('anak@contoh.id');
        $kw->cabut('manual');

        $this->tangkap(['subject_class' => 'anak', 'guardian_consent_id' => $kw->id])
            ->assertStatus(422)
            ->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH);

        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function kewenangan_yang_belum_terverifikasi_ditolak(): void
    {
        $this->ajukan()->assertStatus(202);
        $kw = GuardianConsent::first();

        $this->tangkap(['subject_class' => 'anak', 'guardian_consent_id' => $kw->id])
            ->assertStatus(422)
            ->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH);

        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function kewenangan_yang_sah_meloloskan_anak_lewat_widget(): void
    {
        $kw = $this->kewenanganSah('anak@contoh.id');

        $this->tangkap(['subject_class' => 'anak', 'guardian_consent_id' => $kw->id])->assertStatus(201);

        $log = ConsentLog::first();
        $this->assertSame($kw->id, $log->guardian_consent_id);
        $this->assertSame(KelasSubjek::ANAK, $log->subject_class);
    }

    #[Test]
    public function status_kewenangan_terbaca_lewat_partner_api_tanpa_bocor_lintas_tenant(): void
    {
        $this->ajukan()->assertStatus(202);
        $kw = GuardianConsent::first();

        $this->v1('GET', '/api/v1/consent/guardian/'.$kw->id)
            ->assertOk()
            ->assertJsonPath('status', 'menunggu_wali')
            ->assertJsonMissingPath('verification_token_hash');

        $this->postJson($this->tautanDariSurel())->assertOk();

        $this->v1('GET', '/api/v1/consent/guardian/'.$kw->id)
            ->assertOk()
            ->assertJsonPath('status', 'terverifikasi');

        $lain = Organization::create(['name' => 'PT Lain', 'slug' => 'pt-lain-'.Str::random(6)]);
        $kwLain = $this->kewenanganSah('x@contoh.id', $lain);
        $this->v1('GET', '/api/v1/consent/guardian/'.$kwLain->id)->assertStatus(404);
    }

    // ───────────────────────────── bantu ─────────────────────────────

    /** Penangkapan lewat widget publik — bawaannya subjek dewasa tanpa penanda. */
    private function tangkap(array $tambahan = []): TestResponse
    {
        return $this->postJson('/api/public/consent', array_merge([
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => true],
        ], $tambahan));
    }

    /** Pengajuan wali lewat widget publik. */
    private function ajukan(array $tambahan = []): TestResponse
    {
        return $this->postJson('/api/public/consent/guardian/request', array_merge([
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti R.', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
        ], $tambahan));
    }

    /** Partner API v1 — badan ditandatangani HMAC persis seperti middleware menuntutnya. */
    private function v1(string $method, string $uri, array $payload = []): TestResponse
    {
        $body = $payload === [] ? '' : (string) json_encode($payload);

        return $this->call($method, $uri, [], [], [], [
            'HTTP_X_PRIVASIMU_CLIENT_KEY' => $this->clientKey,
            'HTTP_X_PRIVASIMU_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->serverKey),
            'HTTP_X_PRIVASIMU_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** Ambil tautan dari surel ke-N yang diantrekan — satu-satunya tempat token mentah hidup. */
    private function tautanDariSurel(int $ke = 1): string
    {
        $tautan = [];
        Mail::assertQueued(GuardianVerificationMail::class, function (GuardianVerificationMail $m) use (&$tautan) {
            $tautan[] = $m->verifyUrl;

            return true;
        });

        $url = $tautan[$ke - 1] ?? null;
        $this->assertNotNull($url, "surel ke-{$ke} tidak ditemukan");
        $this->assertMatchesRegularExpression('~/api/public/consent/guardian/verify/[A-Za-z0-9]{64}$~', $url);

        return $url;
    }

    /** Kewenangan yang sudah terverifikasi, dibuat langsung — untuk uji gerbang. */
    private function kewenanganSah(string $penanda, ?Organization $org = null): GuardianConsent
    {
        $org ??= $this->org;

        $subjek = ConsentSubject::temukanAtauBuat($org->id, $penanda, ['subject_class' => KelasSubjek::ANAK]);
        $wali = Guardian::temukanAtauBuat($org->id, 'wali-'.Str::random(4).'@contoh.id', [
            'name' => 'Wali', 'relationship' => 'wali_sah',
        ]);

        return GuardianConsent::create([
            'org_id' => $org->id,
            'consent_subject_id' => $subjek->id,
            'guardian_id' => $wali->id,
            'verified_at' => now(),
            'verification_method_code' => 'otp_email',
            'verification_driver' => 'otp',
            'verification_confidence' => 'rendah',
        ]);
    }
}
