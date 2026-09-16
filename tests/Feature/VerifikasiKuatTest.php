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
use App\Models\TenantRole;
use App\Models\User;
use App\Models\VerificationMethod;
use App\Services\Consent\CacheConfigPublik;
use App\Services\Consent\LayananWali;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifikasi kuat wali (Dukcapil / e-KYC) — PP 33/2026 Pasal 38 ayat (4), Fase 6.
 *
 * Yang kalau salah membuat fitur ini berbahaya:
 *
 *   - NIK / tanggal lahir wali TERSIMPAN di suatu tempat (hanya rujukan
 *     penyedia yang boleh tinggal);
 *   - identitas yang terbukti dianggap persetujuan (ledger ditulis sebelum
 *     wali menekan "Saya menyetujui");
 *   - kegagalan penyedia dilaporkan sebagai "identitas tidak cocok";
 *   - kredensial Dukcapil milik tenant bocor lewat API, atau jadi alat
 *     menebak NIK;
 *   - konfirmasi ulang lewat surel menurunkan keyakinan `tinggi` ke `rendah`.
 */
class VerifikasiKuatTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '3175012001900004';

    private const NIK_GANJIL = '3175012001900001';

    private const LAHIR = '1990-01-20';

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

        $this->org = Organization::factory()->create(['name' => 'Bank Uji']);

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

    // ───────────────────── jalur publik (widget) ─────────────────────

    #[Test]
    public function identitas_cocok_terverifikasi_seketika_tanpa_surel_dan_tanpa_ledger(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $r = $this->ajukanKuat();

        $r->assertOk()
            ->assertJsonPath('status', 'identitas_terverifikasi')
            ->assertJsonPath('verification.method_code', 'dukcapil_bank')
            ->assertJsonPath('verification.driver', 'dukcapil')
            ->assertJsonPath('verification.confidence', 'tinggi')
            ->assertJsonPath('verification.reference', 'TRX-2026-0001')
            ->assertJsonPath('preview.guardian_name', 'Siti Rahayu')
            ->assertJsonPath('preview.subject_class', 'anak');

        $token = (string) $r->json('confirm_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertStringEndsWith('/api/public/consent/guardian/verify/'.$token, (string) $r->json('confirm_url'));

        $kw = GuardianConsent::withoutGlobalScope('org')->sole();
        $this->assertNotNull($kw->verified_at);
        $this->assertSame('dukcapil_bank', $kw->verification_method_code);
        $this->assertSame('tinggi', $kw->verification_confidence);
        $this->assertSame('TRX-2026-0001', $kw->verification_reference);

        // Identitas yang terbukti BUKAN persetujuan: belum ada baris ledger,
        // dan tidak ada surel karena wali sedang di depan layar.
        $this->assertSame(0, ConsentLog::count());
        Mail::assertNotQueued(GuardianVerificationMail::class);
        Queue::assertNotPushed(FireConsentWebhookJob::class);

        $audit = AuditLog::where('action', 'guardian_consent.identity_verified')->sole();
        $this->assertSame('TRX-2026-0001', ((array) $audit->changes)['verification_reference']);
    }

    #[Test]
    public function konfirmasi_token_sesi_menulis_ledger_dengan_pernyataan_yang_dilihat(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $r = $this->ajukanKuat()->assertOk();
        $token = (string) $r->json('confirm_token');
        $pernyataan = (string) $r->json('preview.statement');
        $this->assertStringContainsString('Siti Rahayu', $pernyataan);
        $this->assertStringContainsString('Penawaran tabungan pelajar', $pernyataan);

        $this->postJson('/api/public/consent/guardian/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('subject_class', 'anak');

        $log = ConsentLog::sole();
        $kw = GuardianConsent::withoutGlobalScope('org')->sole();
        $this->assertSame($kw->id, $log->guardian_consent_id);
        $this->assertSame(KelasSubjek::ANAK, $log->subject_class);
        $this->assertSame($pernyataan, $kw->statement_shown);

        // Konfirmasi TIDAK menurunkan hasil verifikasi kuat ke otp_email/rendah.
        $this->assertSame('dukcapil_bank', $kw->verification_method_code);
        $this->assertSame('tinggi', $kw->verification_confidence);

        $audit = AuditLog::where('action', 'guardian_consent.confirm')->sole();
        $this->assertSame('wali (identitas terverifikasi)', $audit->user_name);
        $this->assertSame('dukcapil_bank', ((array) $audit->changes)['verification_method_code']);

        Queue::assertPushed(FireConsentWebhookJob::class);

        // Sekali pakai.
        $this->postJson('/api/public/consent/guardian/verify/'.$token)
            ->assertStatus(404)->assertJsonPath('code', LayananWali::TOKEN_TIDAK_DIKENAL);
        $this->assertSame(1, ConsentLog::count());
    }

    #[Test]
    public function identitas_tidak_cocok_ditolak_tanpa_meninggalkan_baris(): void
    {
        $this->metodeDukcapil();

        // Satu stub yang jawabannya diganti per fase — Http::fake() yang
        // dipanggil dua kali MENGGABUNGKAN stub, dan yang pertama menang.
        $jawab = fn () => Http::response(['content' => [['NAMA_LGKP' => 'Tidak Sesuai', 'TGL_LHR' => 'Sesuai']]], 200);
        Http::fake(function () use (&$jawab) {
            return $jawab();
        });
        $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::IDENTITAS_TIDAK_COCOK);

        // "Data Tidak Ditemukan" — 200 dengan bidang lain — lewat mismatch_any.
        $jawab = fn () => Http::response(['content' => [['RESPON' => 'Data Tidak Ditemukan']]], 200);
        $this->ajukanKuat()->assertStatus(422)
            ->assertJsonPath('code', LayananWali::IDENTITAS_TIDAK_COCOK);
        Http::assertSentCount(2);

        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
        $this->assertSame(0, Guardian::withoutGlobalScope('org')->count());
        $this->assertSame(0, ConsentSubject::withoutGlobalScope('org')->count());
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function penyedia_gagal_dibedakan_dari_tidak_cocok(): void
    {
        $this->metodeDukcapil();

        // Penyedia tumbang.
        $jawab = fn () => Http::response('Service Unavailable', 503);
        Http::fake(function () use (&$jawab) {
            return $jawab();
        });
        $this->ajukanKuat()->assertStatus(503)->assertJsonPath('code', LayananWali::PENYEDIA_GAGAL);

        // 200 tetapi kontraknya lain (kunci tenant ditolak): bidang kecocokan
        // tidak ada → GAGAL, bukan "identitas Anda tidak cocok".
        $jawab = fn () => Http::response(['error' => 'invalid api key'], 200);
        $this->ajukanKuat()->assertStatus(503)->assertJsonPath('code', LayananWali::PENYEDIA_GAGAL);

        // Koneksi putus.
        $jawab = fn () => throw new ConnectionException('cURL error 28: Operation timed out');
        $this->ajukanKuat()->assertStatus(503)->assertJsonPath('code', LayananWali::PENYEDIA_GAGAL);

        // Dua yang sampai ke penyedia; yang ketiga putus sebelum tercatat.
        Http::assertSentCount(2);
        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
        $this->assertSame(0, ConsentSubject::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function klaim_identitas_dikirim_ke_penyedia_dan_tidak_tersimpan_di_mana_pun(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $r = $this->ajukanKuat()->assertOk();
        $this->postJson('/api/public/consent/guardian/verify/'.$r->json('confirm_token'))->assertOk();

        // Klaim memang sampai ke penyedia — dengan kredensial tenant dan
        // tanggal dalam format yang diminta kontraknya.
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://dukcapil.contoh.go.id/verify'
            && $req['NIK'] === self::NIK
            && $req['NAMA_LGKP'] === 'Siti Rahayu'
            && $req['TGL_LHR'] === '20-01-1990'
            && $req['user_id'] === 'bankuji'
            && $req->hasHeader('Authorization', 'Bearer RAHASIA-TENANT'));

        // …dan hanya ke sana.
        foreach (['guardians', 'guardian_consents', 'consent_subjects', 'consent_logs', 'audit_logs'] as $tabel) {
            foreach (DB::table($tabel)->get() as $baris) {
                $dump = (string) json_encode((array) $baris);
                $this->assertStringNotContainsString(self::NIK, $dump, "NIK bocor di {$tabel}");
                $this->assertStringNotContainsString(self::LAHIR, $dump, "tanggal lahir bocor di {$tabel}");
                $this->assertStringNotContainsString('20-01-1990', $dump, "tanggal lahir bocor di {$tabel}");
            }
        }

        // Termasuk di dalam kolom yang tersandi.
        $kw = GuardianConsent::withoutGlobalScope('org')->sole();
        $this->assertStringNotContainsString(self::NIK, (string) json_encode($kw->pending_capture));
    }

    #[Test]
    public function metode_yang_tidak_bisa_dipakai_ditolak_terbuka_tanpa_memanggil_penyedia(): void
    {
        Http::fake();

        // Belum ada metode kuat sama sekali.
        $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Nonaktif.
        $m = $this->metodeDukcapil(['is_active' => false]);
        $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Milik tenant lain.
        $m->update(['is_active' => true, 'org_id' => Organization::factory()->create()->id]);
        $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Bukan metode kuat (bawaan platform otp_email).
        $this->ajukanKuat(['verification' => ['method_code' => 'otp_email', 'nik' => self::NIK, 'birth_date' => self::LAHIR]])
            ->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Terdaftar tetapi belum bisa dijalankan (tanpa endpoint).
        $m->update(['org_id' => $this->org->id, 'config' => ['match_all' => [['path' => 'ok', 'equals' => true]]]]);
        $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        Http::assertNothingSent();
        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function percobaan_identitas_dibatasi_per_titik_dan_ip(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response([
            'content' => [['NAMA_LGKP' => 'Tidak Sesuai', 'TGL_LHR' => 'Tidak Sesuai']],
        ], 200)]);

        for ($i = 0; $i < LayananWali::BATAS_PERCOBAAN_IDENTITAS; $i++) {
            $this->ajukanKuat()->assertStatus(422)->assertJsonPath('code', LayananWali::IDENTITAS_TIDAK_COCOK);
        }

        // Percobaan keenam tidak pernah sampai ke penyedia — kredensial
        // Dukcapil milik tenant bukan alat menebak NIK.
        $this->ajukanKuat()->assertStatus(429)->assertJsonPath('code', LayananWali::TERLALU_BANYAK_PERCOBAAN);
        Http::assertSentCount(LayananWali::BATAS_PERCOBAAN_IDENTITAS);
    }

    #[Test]
    public function token_sesi_berumur_lima_belas_menit(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $r = $this->ajukanKuat()->assertOk();
        $sampai = Carbon::parse((string) $r->json('expires_at'));
        $this->assertTrue($sampai->between(now()->addMinutes(14), now()->addMinutes(16)), 'token sesi harus 15 menit, bukan 24 jam');

        $this->travel(16)->minutes();

        $this->postJson('/api/public/consent/guardian/verify/'.$r->json('confirm_token'))
            ->assertStatus(410)->assertJsonPath('code', LayananWali::TOKEN_KEDALUWARSA);
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function verifikasi_kuat_menaikkan_keyakinan_dan_surel_tidak_menurunkannya_lagi(): void
    {
        // Kewenangan yang sudah ada lewat surel — keyakinan rendah.
        $subjek = ConsentSubject::temukanAtauBuat($this->org->id, 'anak@contoh.id', ['subject_class' => KelasSubjek::ANAK]);
        $wali = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti Rahayu', 'relationship' => 'orang_tua']);
        $lama = GuardianConsent::create([
            'org_id' => $this->org->id,
            'consent_subject_id' => $subjek->id,
            'guardian_id' => $wali->id,
            'verified_at' => now()->subDay(),
            'verification_method_code' => 'otp_email',
            'verification_driver' => 'otp',
            'verification_confidence' => 'rendah',
        ]);

        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $this->ajukanKuat()->assertOk();

        // Baris yang sama, dinaikkan — bukan baris kedua.
        $this->assertSame(1, GuardianConsent::withoutGlobalScope('org')->count());
        $kw = $lama->fresh();
        $this->assertSame('tinggi', $kw->verification_confidence);
        $this->assertSame('dukcapil_bank', $kw->verification_method_code);
        $this->assertTrue($kw->verified_at->gt($lama->verified_at));

        // Pengajuan berikutnya lewat surel biasa: konfirmasinya tidak boleh
        // menulis ulang `otp_email` / `rendah` di atas hasil Dukcapil.
        $this->postJson('/api/public/consent/guardian/request', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
        ])->assertStatus(202);

        $this->postJson($this->tautanDariSurel())->assertOk();

        $kw->refresh();
        $this->assertSame('tinggi', $kw->verification_confidence);
        $this->assertSame('dukcapil_bank', $kw->verification_method_code);
        $this->assertSame(1, ConsentLog::count());
    }

    #[Test]
    public function kontak_telepon_diterima_pada_jalur_identitas(): void
    {
        // Jalur surel menolak telepon (belum ada driver SMS). Jalur identitas
        // tidak mengirim apa pun ke wali, jadi kanalnya bebas.
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $this->ajukanKuat(['guardian' => ['name' => 'Siti Rahayu', 'contact' => '081234567890', 'relationship' => 'orang_tua']])
            ->assertOk()->assertJsonPath('status', 'identitas_terverifikasi');

        Mail::assertNothingQueued();
        $this->assertSame(1, Guardian::withoutGlobalScope('org')->count());
    }

    // ───────────────────── partner API v1 ─────────────────────

    #[Test]
    public function jalur_partner_api_memakai_token_sesi_dan_dibatasi_ke_tenant_pemilik_kunci(): void
    {
        $this->metodeDukcapil();
        Http::fake(['dukcapil.contoh.go.id/*' => Http::response($this->jawabanSesuai(), 200)]);

        $r = $this->v1('POST', '/api/v1/consent/guardian/request', [
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
            'verification' => ['method_code' => 'dukcapil_bank', 'nik' => self::NIK, 'birth_date' => self::LAHIR],
        ]);
        $r->assertOk()
            ->assertJsonPath('status', 'identitas_terverifikasi')
            ->assertJsonPath('confirm_url', null)
            ->assertJsonPath('verification.confidence', 'tinggi');
        $token = (string) $r->json('confirm_token');
        $this->assertSame(0, ConsentLog::count());

        // Kunci tenant LAIN dengan token yang sah → 404, bukan ledger tenant lain.
        [$ck2, $sk2] = $this->kunciTenantLain();
        $this->v1('POST', '/api/v1/consent/guardian/confirm', ['token' => $token], $ck2, $sk2)
            ->assertStatus(404)->assertJsonPath('code', LayananWali::TOKEN_TIDAK_DIKENAL);
        $this->assertSame(0, ConsentLog::count());

        // Kunci sendiri.
        $this->v1('POST', '/api/v1/consent/guardian/confirm', ['token' => $token])
            ->assertOk()->assertJsonPath('subject_class', 'anak');

        $log = ConsentLog::sole();
        $this->assertSame($this->org->id, $log->org_id);
        $this->assertSame($this->cp->id, $log->collection_id);

        // Status kewenangan lewat v1 memperlihatkan metode dan keyakinannya.
        $this->v1('GET', '/api/v1/consent/guardian/'.$log->guardian_consent_id)
            ->assertOk()
            ->assertJsonPath('status', 'terverifikasi')
            ->assertJsonPath('verification_method_code', 'dukcapil_bank')
            ->assertJsonPath('verification_confidence', 'tinggi');
    }

    // ───────────────────── driver simulasi ─────────────────────

    #[Test]
    public function driver_simulasi_bekerja_di_luar_produksi_dan_lenyap_di_produksi(): void
    {
        $this->metodeDukcapil(['code' => 'simulasi', 'label' => 'Simulasi Dukcapil', 'driver' => 'mock', 'confidence' => 'rendah', 'config' => null]);
        $klaim = fn (string $nik) => ['verification' => ['method_code' => 'simulasi', 'nik' => $nik, 'birth_date' => self::LAHIR]];

        // Tanpa pengaturan: NIK genap cocok, ganjil tidak.
        $this->ajukanKuat($klaim(self::NIK))->assertOk()->assertJsonPath('verification.driver', 'mock');
        $this->ajukanKuat($klaim(self::NIK_GANJIL))->assertStatus(422)->assertJsonPath('code', LayananWali::IDENTITAS_TIDAK_COCOK);

        $kode = fn () => collect($this->getJson('/api/public/consent/config?collection_id='.$this->cp->collection_id)
            ->assertOk()->json('data.guardian_verification.methods'))->pluck('code')->all();
        $this->assertContains('simulasi', $kode());

        // Produksi: tidak dikenal widget, tidak ditawarkan config, tidak bisa dibuat.
        // (config('app.env'), bukan $app['env'] — yang kedua mengalihkan model
        // landlord ke koneksi lain dan mematahkan seluruh uji.)
        config(['app.env' => 'production']);
        CacheConfigPublik::segarkanOrg($this->org->id);

        $this->ajukanKuat($klaim(self::NIK))->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);
        $this->assertNotContains('simulasi', $kode());

        Sanctum::actingAs($this->pengguna());
        $this->postJson('/api/verification-methods', ['code' => 'simulasi2', 'label' => 'Simulasi', 'driver' => 'mock'])
            ->assertStatus(422)->assertJsonValidationErrors(['driver']);
    }

    // ───────────────────── katalog admin ─────────────────────

    #[Test]
    public function admin_membuat_metode_dan_kredensialnya_tidak_pernah_kembali(): void
    {
        Sanctum::actingAs($this->pengguna());

        $r = $this->postJson('/api/verification-methods', [
            'code' => 'dukcapil_bank',
            'label' => 'Verifikasi Dukcapil',
            'driver' => 'dukcapil',
            'config' => [
                'endpoint' => 'https://dukcapil.contoh.go.id/verify',
                'headers' => ['Authorization' => 'Bearer RAHASIA-TENANT'],
                'body' => ['user_id' => 'bankuji', 'password' => 'sandi-rahasia', 'NIK' => '{nik}'],
                'match_all' => [['path' => 'content.0.NAMA_LGKP', 'equals' => 'Sesuai']],
            ],
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('data.confidence', 'tinggi')
            ->assertJsonPath('data.strong', true)
            ->assertJsonPath('data.runnable', true)
            ->assertJsonPath('data.is_platform_default', false)
            ->assertJsonPath('data.review_at', now()->addYear()->toDateString())
            ->assertJsonPath('data.config.header_keys', ['Authorization'])
            ->assertJsonPath('data.config.body.NIK', '{nik}')
            ->assertJsonPath('data.config.body.password', VerificationMethod::TERSAMAR)
            ->assertJsonPath('data.config.body.user_id', VerificationMethod::TERSAMAR);

        $index = $this->getJson('/api/verification-methods')->assertOk();
        foreach (['RAHASIA-TENANT', 'sandi-rahasia', 'bankuji'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $r->getContent());
            $this->assertStringNotContainsString($rahasia, (string) $index->getContent());
        }

        // Bawaan platform tampil lebih dulu, ditandai, dan tanpa config.
        $this->assertTrue($index->json('data.0.is_platform_default'));
        $this->assertNull($index->json('data.0.config'));
        $this->assertSame(['dukcapil_bank', 'otp_email', 'otp_phone'], collect($index->json('data'))->pluck('code')->sort()->values()->all());

        // Tersandi di basis data, utuh di model.
        $mentah = (string) DB::table('verification_methods')->where('code', 'dukcapil_bank')->value('config');
        $this->assertStringNotContainsString('RAHASIA-TENANT', $mentah);
        $this->assertSame('Bearer RAHASIA-TENANT', VerificationMethod::where('code', 'dukcapil_bank')->sole()->config['headers']['Authorization']);

        // Duplikat kode sendiri → 409.
        $this->postJson('/api/verification-methods', ['code' => 'dukcapil_bank', 'label' => 'Lagi', 'driver' => 'dukcapil'])
            ->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA');
    }

    #[Test]
    public function bawaan_platform_hanya_bisa_dibaca_dan_kodenya_tidak_bisa_dibajak(): void
    {
        Sanctum::actingAs($this->pengguna());
        $otp = VerificationMethod::whereNull('org_id')->where('code', 'otp_email')->sole();

        $this->putJson('/api/verification-methods/'.$otp->id, ['label' => 'Diubah'])
            ->assertStatus(403)->assertJsonPath('code', 'BAWAAN_PLATFORM');
        $this->deleteJson('/api/verification-methods/'.$otp->id)
            ->assertStatus(403)->assertJsonPath('code', 'BAWAAN_PLATFORM');
        $this->postJson('/api/verification-methods/'.$otp->id.'/test', ['nik' => self::NIK, 'name' => 'Siti', 'birth_date' => self::LAHIR])
            ->assertStatus(422)->assertJsonPath('code', 'BUKAN_METODE_KUAT');

        // Tenant tidak bisa membuat `otp_email` versinya sendiri — untukOrg()
        // akan mengembalikan dua baris berkode sama.
        $this->postJson('/api/verification-methods', [
            'code' => 'otp_email', 'label' => 'Palsu', 'driver' => 'dukcapil',
            'config' => ['endpoint' => 'https://x.contoh.id', 'match_all' => [['path' => 'ok', 'equals' => true]]],
        ])->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA');

        $this->assertSame('Tautan verifikasi ke surel wali', $otp->fresh()->label);
    }

    #[Test]
    public function pembaruan_mempertahankan_rahasia_yang_tidak_dikirim_dan_bersifat_patch(): void
    {
        Sanctum::actingAs($this->pengguna());
        $m = $this->metodeDukcapil();
        $url = '/api/verification-methods/'.$m->id;

        // UI mengirim balik badan dengan nilai samaran + timeout baru, tanpa headers.
        $this->putJson($url, [
            'label' => 'Dukcapil v2',
            'config' => [
                'timeout' => 20,
                'body' => ['user_id' => VerificationMethod::TERSAMAR, 'password' => VerificationMethod::TERSAMAR, 'NIK' => '{nik}', 'NAMA_LGKP' => '{name}', 'TGL_LHR' => '{birth_date}'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.label', 'Dukcapil v2')
            ->assertJsonPath('data.config.timeout', 20);

        $cfg = $m->fresh()->config;
        $this->assertSame('Bearer RAHASIA-TENANT', $cfg['headers']['Authorization'], 'headers tidak dikirim → dipertahankan');
        $this->assertSame('sandi-rahasia', $cfg['body']['password'], 'nilai samaran → dipertahankan');
        $this->assertSame('bankuji', $cfg['body']['user_id']);
        $this->assertSame('https://dukcapil.contoh.go.id/verify', $cfg['endpoint'], 'PATCH: yang tidak disebut tetap');
        $this->assertSame(20, $cfg['timeout']);

        // null eksplisit menghapus.
        $this->putJson($url, ['config' => ['headers' => null]])->assertOk();
        $this->assertArrayNotHasKey('headers', $m->fresh()->config);

        // Driver HTTP tanpa endpoint tidak sah.
        $this->putJson($url, ['config' => ['endpoint' => null]])
            ->assertStatus(422)->assertJsonValidationErrors(['config.endpoint']);

        // Audit mencatat bidang, bukan isi config.
        foreach (AuditLog::where('action', 'verification_method.update')->get() as $a) {
            $this->assertStringNotContainsString('RAHASIA-TENANT', (string) json_encode($a->changes));
            $this->assertStringNotContainsString('sandi-rahasia', (string) json_encode($a->changes));
        }
    }

    #[Test]
    public function uji_metode_menjalankan_driver_tanpa_menyimpan_klaim(): void
    {
        Sanctum::actingAs($this->pengguna());
        $m = $this->metodeDukcapil();
        $jawab = fn () => Http::response($this->jawabanSesuai(), 200);
        Http::fake(function () use (&$jawab) {
            return $jawab();
        });

        $this->postJson('/api/verification-methods/'.$m->id.'/test', ['nik' => self::NIK, 'name' => 'Siti Rahayu', 'birth_date' => self::LAHIR])
            ->assertOk()
            ->assertJsonPath('data.status', 'cocok')
            ->assertJsonPath('data.reference', 'TRX-2026-0001')
            ->assertJsonPath('data.http_status', 200);

        Http::assertSentCount(1);

        $audit = AuditLog::where('action', 'verification_method.test')->sole();
        $this->assertSame('cocok', ((array) $audit->changes)['status']);
        $this->assertStringNotContainsString(self::NIK, (string) json_encode($audit->changes));
        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());

        // Kegagalan dilaporkan apa adanya kepada admin — inilah gunanya uji.
        $jawab = fn () => Http::response(['message' => 'unauthorized'], 401);
        $gagal = $this->postJson('/api/verification-methods/'.$m->id.'/test', ['nik' => self::NIK, 'name' => 'Siti Rahayu', 'birth_date' => self::LAHIR])
            ->assertOk()
            ->assertJsonPath('data.status', 'gagal')
            ->assertJsonPath('data.http_status', 401);
        // Alasannya menyebut bidang yang hilang dan status HTTP — cukup bagi
        // admin untuk tahu kredensialnya ditolak, tanpa menyalin respons mentah.
        $this->assertStringContainsString('content.0.NAMA_LGKP', (string) $gagal->json('data.reason'));
        $this->assertStringContainsString('HTTP 401', (string) $gagal->json('data.reason'));
    }

    #[Test]
    public function config_publik_hanya_menawarkan_metode_yang_bisa_dijalankan_dan_segera(): void
    {
        $metode = fn () => collect($this->getJson('/api/public/consent/config?collection_id='.$this->cp->collection_id)
            ->assertOk()->json('data.guardian_verification.methods'));

        // Bawaan: hanya otp_email. otp_phone terdaftar tetapi belum punya driver.
        $this->assertSame(['otp_email'], $metode()->pluck('code')->all());
        $this->assertFalse($metode()->firstWhere('code', 'otp_email')['strong']);

        // Admin membuat metode kuat → segera terlihat (cache disegarkan).
        Sanctum::actingAs($this->pengguna());
        $id = $this->postJson('/api/verification-methods', [
            'code' => 'dukcapil_bank', 'label' => 'Verifikasi Dukcapil', 'driver' => 'dukcapil',
            'config' => ['endpoint' => 'https://dukcapil.contoh.go.id/verify', 'match_all' => [['path' => 'ok', 'equals' => true]]],
        ])->assertStatus(201)->json('data.id');

        $this->assertSame(['dukcapil_bank', 'otp_email'], $metode()->pluck('code')->sort()->values()->all());
        $this->assertTrue($metode()->firstWhere('code', 'dukcapil_bank')['strong']);
        $this->assertSame('tinggi', $metode()->firstWhere('code', 'dukcapil_bank')['confidence']);

        // Dinonaktifkan → hilang seketika.
        $this->putJson('/api/verification-methods/'.$id, ['is_active' => false])->assertOk()->assertJsonPath('data.runnable', false);
        $this->assertSame(['otp_email'], $metode()->pluck('code')->all());

        // Aktif tetapi belum lengkap (tanpa endpoint) → tidak dijanjikan.
        VerificationMethod::create([
            'org_id' => $this->org->id, 'code' => 'ekyc_kosong', 'label' => 'e-KYC', 'driver' => 'ekyc',
            'confidence' => 'tinggi', 'is_active' => true, 'config' => null,
        ]);
        CacheConfigPublik::segarkanOrg($this->org->id);
        $this->assertSame(['otp_email'], $metode()->pluck('code')->all());
    }

    #[Test]
    public function tenant_lain_tidak_melihat_dan_tidak_menyentuh_metode_tenant_ini(): void
    {
        $m = $this->metodeDukcapil();
        $lain = Organization::factory()->create();
        Sanctum::actingAs($this->pengguna(org: $lain));

        $this->assertSame(
            ['otp_email', 'otp_phone'],
            collect($this->getJson('/api/verification-methods')->assertOk()->json('data'))->pluck('code')->sort()->values()->all(),
        );
        $this->putJson('/api/verification-methods/'.$m->id, ['label' => 'Bajak'])->assertStatus(404);
        $this->deleteJson('/api/verification-methods/'.$m->id)->assertStatus(404);
        $this->postJson('/api/verification-methods/'.$m->id.'/test', ['nik' => self::NIK, 'name' => 'X', 'birth_date' => self::LAHIR])->assertStatus(404);

        $this->assertSame('Verifikasi Dukcapil (Bank Uji)', $m->fresh()->label);
    }

    #[Test]
    public function izin_baca_tidak_cukup_untuk_menulis_atau_menguji(): void
    {
        $m = $this->metodeDukcapil();
        Sanctum::actingAs($this->pengguna(['consent:read']));

        $this->getJson('/api/verification-methods')->assertOk();
        $this->postJson('/api/verification-methods', ['code' => 'x_baru', 'label' => 'x', 'driver' => 'dukcapil'])->assertStatus(403);
        $this->putJson('/api/verification-methods/'.$m->id, ['label' => 'x'])->assertStatus(403);
        $this->deleteJson('/api/verification-methods/'.$m->id)->assertStatus(403);
        $this->postJson('/api/verification-methods/'.$m->id.'/test', ['nik' => self::NIK, 'name' => 'X', 'birth_date' => self::LAHIR])->assertStatus(403);
    }

    // ───────────────────────── bantu ─────────────────────────

    /** @param  array<string, mixed>  $override */
    private function metodeDukcapil(array $override = []): VerificationMethod
    {
        return VerificationMethod::create(array_merge([
            'org_id' => $this->org->id,
            'code' => 'dukcapil_bank',
            'label' => 'Verifikasi Dukcapil (Bank Uji)',
            'driver' => VerificationMethod::DRIVER_DUKCAPIL,
            'confidence' => 'tinggi',
            'is_active' => true,
            'config' => [
                'endpoint' => 'https://dukcapil.contoh.go.id/verify',
                'method' => 'POST',
                'timeout' => 5,
                'headers' => ['Authorization' => 'Bearer RAHASIA-TENANT'],
                'body' => [
                    'user_id' => 'bankuji',
                    'password' => 'sandi-rahasia',
                    'NIK' => '{nik}',
                    'NAMA_LGKP' => '{name}',
                    'TGL_LHR' => '{birth_date}',
                ],
                'birth_date_format' => 'd-m-Y',
                'mismatch_any' => [['path' => 'content.0.RESPON', 'equals' => 'Data Tidak Ditemukan']],
                'match_all' => [
                    ['path' => 'content.0.NAMA_LGKP', 'equals' => 'Sesuai'],
                    ['path' => 'content.0.TGL_LHR', 'equals' => 'Sesuai'],
                ],
                'reference_path' => 'content.0.TRX_ID',
                'reason_path' => 'content.0.RESPON',
            ],
        ], $override));
    }

    /** @return array<string, mixed> */
    private function jawabanSesuai(): array
    {
        return ['content' => [['NAMA_LGKP' => 'Sesuai', 'TGL_LHR' => 'Sesuai', 'TRX_ID' => 'TRX-2026-0001']]];
    }

    /** @param  array<string, mixed>  $tambahan */
    private function ajukanKuat(array $tambahan = []): TestResponse
    {
        return $this->postJson('/api/public/consent/guardian/request', array_merge([
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
            'verification' => ['method_code' => 'dukcapil_bank', 'nik' => self::NIK, 'birth_date' => self::LAHIR],
        ], $tambahan));
    }

    /** @param  list<string>  $izin */
    private function pengguna(array $izin = ['consent:read', 'consent:write'], ?Organization $org = null): User
    {
        $org ??= $this->org;

        return User::factory()->create([
            'org_id' => $org->id,
            'role' => 'admin',
            'tenant_role_id' => TenantRole::create([
                'org_id' => $org->id,
                'name' => 'peran-'.uniqid(),
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
        ]);
    }

    /**
     * Partner API v1 — badan ditandatangani HMAC persis seperti middleware menuntutnya.
     *
     * @param  array<string, mixed>  $payload
     */
    private function v1(string $method, string $uri, array $payload = [], ?string $clientKey = null, ?string $serverKey = null): TestResponse
    {
        $body = $payload === [] ? '' : (string) json_encode($payload);

        return $this->call($method, $uri, [], [], [], [
            'HTTP_X_PRIVASIMU_CLIENT_KEY' => $clientKey ?? $this->clientKey,
            'HTTP_X_PRIVASIMU_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $serverKey ?? $this->serverKey),
            'HTTP_X_PRIVASIMU_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** @return array{0: string, 1: string} */
    private function kunciTenantLain(): array
    {
        $lain = Organization::factory()->create(['name' => 'Tenant Lain']);
        $cp = ConsentCollectionPoint::create([
            'org_id' => $lain->id,
            'collection_id' => 'CNT-2026-'.Str::random(3),
            'name' => 'Formulir Lain',
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);
        [$ck, $sk] = ConsentCollectionPoint::generateApiKeyPair();
        $cp->update(['client_key' => $ck, 'server_key' => $sk, 'auth_methods' => ['widget' => true, 'api_key' => true]]);

        return [$ck, $sk];
    }

    /** Tautan dari surel yang diantrekan — satu-satunya tempat token surel hidup. */
    private function tautanDariSurel(): string
    {
        $tautan = null;
        Mail::assertQueued(GuardianVerificationMail::class, function (GuardianVerificationMail $m) use (&$tautan) {
            $tautan = $m->verifyUrl;

            return true;
        });
        $this->assertNotNull($tautan);

        return (string) $tautan;
    }
}
