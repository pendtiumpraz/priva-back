<?php

namespace Tests\Feature;

use App\Jobs\KirimPesanSingkatJob;
use App\Mail\GuardianVerificationMail;
use App\Mail\PeralihanDewasaMail;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\TenantRole;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Services\Consent\LayananPeralihan;
use App\Services\Consent\LayananWali;
use App\Services\Pesan\KanalPesan;
use App\Services\Pesan\PesanSingkat;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal telepon (SMS/WhatsApp) untuk tautan wali & peralihan — Fase 7.
 *
 * Yang dijaga: telepon hanya diterima bila kanal pesan platform HIDUP
 * (terdaftar ≠ bisa dijalankan); pesan tidak membawa penanda anak maupun
 * tujuan; konfirmasi dari tautan telepon dicatat sebagai `otp_phone`;
 * driver HTTP memetakan kontrak gateway dengan benar; rahasia gateway tidak
 * pernah kembali lewat API; hanya superadmin yang bisa mengaturnya.
 */
class KanalTeleponTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ConsentCollectionPoint $cp;

    private ConsentItem $item;

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
        ]);
        $this->item = ConsentItem::create([
            'collection_point_id' => $this->cp->id,
            'title' => 'Penawaran tabungan pelajar',
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);
    }

    // ───────────────────── alur wali ─────────────────────

    #[Test]
    public function tanpa_kanal_pesan_kontak_telepon_ditolak_terbuka_dan_widget_tidak_ditawari(): void
    {
        config(['messaging.sms.driver' => 'off']);

        $this->ajukan(['guardian' => $this->wali('081234567890')])
            ->assertStatus(422)->assertJsonPath('code', LayananWali::KANAL_BELUM_DIDUKUNG);

        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
        $this->assertSame(0, Guardian::withoutGlobalScope('org')->count());
        Queue::assertNotPushed(KirimPesanSingkatJob::class);

        $this->assertNotContains('otp_phone', $this->metodeDiConfig());
    }

    #[Test]
    public function nomor_telepon_tidak_valid_ditolak_tanpa_meninggalkan_baris(): void
    {
        config(['messaging.sms.driver' => 'log']);

        foreach (['0215551234', '0812345', 'bukan nomor'] as $buruk) {
            $this->ajukan(['guardian' => $this->wali($buruk)])
                ->assertStatus(422)->assertJsonPath('code', LayananWali::KONTAK_TIDAK_VALID);
        }

        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
        Queue::assertNotPushed(KirimPesanSingkatJob::class);
    }

    #[Test]
    public function dengan_kanal_pesan_tautan_wali_dikirim_lewat_pesan_bukan_surel(): void
    {
        config(['messaging.sms.driver' => 'log']);

        $this->ajukan(['guardian' => $this->wali('0812-3456-7890')])
            ->assertStatus(202)->assertJsonPath('status', 'menunggu_wali');

        Mail::assertNotQueued(GuardianVerificationMail::class);
        Queue::assertPushed(KirimPesanSingkatJob::class, function (KirimPesanSingkatJob $job) {
            $p = $job->pesan;
            $this->assertSame('+6281234567890', $p->tujuan);
            $this->assertSame(PesanSingkat::KONTEKS_TAUTAN_WALI, $p->konteks);
            $this->assertMatchesRegularExpression('~/wali/[A-Za-z0-9]{64}~', $p->teks);
            $this->assertStringContainsString('Bank Uji', $p->teks);
            $this->assertStringContainsString('Siti Rahayu', $p->teks);
            $this->assertStringContainsString('orang tua', $p->teks);
            // Minim: penanda anak dan tujuan pemrosesan tidak lewat SMS.
            $this->assertStringNotContainsString('anak@contoh.id', $p->teks);
            $this->assertStringNotContainsString('Penawaran tabungan pelajar', $p->teks);

            return true;
        });

        $wali = Guardian::withoutGlobalScope('org')->sole();
        $this->assertSame('phone', $wali->contact_type);
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function konfirmasi_dari_tautan_telepon_mencatat_otp_phone(): void
    {
        config(['messaging.sms.driver' => 'log']);
        $this->ajukan(['guardian' => $this->wali('081234567890')])->assertStatus(202);

        $this->postJson($this->tautanDariPesan())->assertOk()->assertJsonPath('subject_class', 'anak');

        $kw = GuardianConsent::withoutGlobalScope('org')->sole();
        $this->assertSame('otp_phone', $kw->verification_method_code);
        $this->assertSame('otp', $kw->verification_driver);
        $this->assertSame('rendah', $kw->verification_confidence);
        $this->assertSame(1, ConsentLog::count());

        $audit = AuditLog::where('action', 'guardian_consent.confirm')->sole();
        $this->assertSame('wali (tautan telepon)', $audit->user_name);
        $this->assertSame('otp_phone', ((array) $audit->changes)['verification_method_code']);
    }

    #[Test]
    public function kirim_ulang_ke_telepon_ditolak_terbuka_bila_kanal_dimatikan_kemudian(): void
    {
        config(['messaging.sms.driver' => 'log']);
        $id = $this->ajukan(['guardian' => $this->wali('081234567890')])->assertStatus(202)->json('guardian_consent_id');

        // Kanal dimatikan setelah kewenangan dibuat — kirim ulang tidak boleh
        // mengaku "terkirim".
        config(['messaging.sms.driver' => 'off']);
        Sanctum::actingAs($this->pengguna());
        $this->postJson('/api/consent-guardian/guardian-consents/'.$id.'/resend')
            ->assertStatus(422)->assertJsonPath('code', LayananWali::KANAL_BELUM_DIDUKUNG);
    }

    #[Test]
    public function jalur_identitas_menolak_kontak_telepon_yang_tidak_valid(): void
    {
        $this->ajukan([
            'guardian' => $this->wali('0215551234'),
            'verification' => ['method_code' => 'apa_saja', 'nik' => '3175012001900004', 'birth_date' => '1990-01-20'],
        ])->assertStatus(422)->assertJsonPath('code', LayananWali::KONTAK_TIDAK_VALID);

        $this->assertSame(0, Guardian::withoutGlobalScope('org')->count());
    }

    // ───────────────────── driver HTTP ─────────────────────

    #[Test]
    public function driver_http_memformat_nomor_mengisi_templat_dan_membaca_jawaban_gateway(): void
    {
        $cfg = [
            'driver' => 'http',
            'http_url' => 'https://gw.contoh.id/send?key={secret}',
            'http_method' => 'POST',
            'http_headers' => '{"Authorization": "Bearer {secret}", "X-Sender": "{sender}"}',
            'http_secret' => 'RAHASIA-GW',
            'http_body' => '{"target": "{to}", "message": "{message}", "meta": {"app": "privasimu"}}',
            'http_success_path' => 'status',
            'http_success_equals' => 'success',
            'to_format' => 'local',
            'sender_name' => 'Bank Uji',
            'timeout' => 5,
        ];
        $pesan = new PesanSingkat('+6281234567890', 'Halo "wali", tautan: https://x/y', PesanSingkat::KONTEKS_UJI);

        // Satu stub yang jawabannya diganti per fase — Http::fake() dua kali
        // MENGGABUNGKAN stub dan yang pertama menang.
        $jawab = fn () => Http::response(['status' => 'success', 'message_id' => 'MSG-1'], 200);
        Http::fake(function () use (&$jawab) {
            return $jawab();
        });
        $hasil = app(KanalPesan::class)->kirim($pesan, $cfg);
        $this->assertTrue($hasil->ok, (string) $hasil->alasan);
        $this->assertSame(200, $hasil->httpStatus);
        $this->assertSame('MSG-1', $hasil->referensi);

        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://gw.contoh.id/send?key=RAHASIA-GW'
            && $req['target'] === '081234567890'
            && $req['message'] === 'Halo "wali", tautan: https://x/y'
            && $req['meta']['app'] === 'privasimu'
            && $req->hasHeader('Authorization', 'Bearer RAHASIA-GW')
            && $req->hasHeader('X-Sender', 'Bank Uji'));

        // Gateway menjawab 200 tetapi menolak — bukan sukses.
        $jawab = fn () => Http::response(['status' => 'failed', 'message' => 'saldo habis'], 200);
        $gagal = app(KanalPesan::class)->kirim($pesan, $cfg);
        $this->assertFalse($gagal->ok);
        $this->assertStringContainsString('status', (string) $gagal->alasan);

        // HTTP 500.
        $jawab = fn () => Http::response('oops', 500);
        $this->assertFalse(app(KanalPesan::class)->kirim($pesan, $cfg)->ok);

        // Tanpa success_path: 2xx sudah cukup, dan format digits.
        $jawab = fn () => Http::response('OK', 200);
        $ok = app(KanalPesan::class)->kirim($pesan, array_merge($cfg, ['http_success_path' => null, 'to_format' => 'digits']));
        $this->assertTrue($ok->ok);
        Http::assertSent(fn (HttpRequest $req) => $req['target'] === '6281234567890');
    }

    #[Test]
    public function job_pengiriman_melempar_saat_gateway_menolak_supaya_diulang(): void
    {
        config([
            'messaging.sms.driver' => 'http',
            'messaging.sms.http_url' => 'https://gw.contoh.id/send',
            'messaging.sms.http_body' => '{"to":"{to}","message":"{message}"}',
        ]);
        Http::fake(['gw.contoh.id/*' => Http::response('down', 503)]);

        $job = new KirimPesanSingkatJob(new PesanSingkat('+6281234567890', 'uji', PesanSingkat::KONTEKS_UJI));
        $this->assertSame(3, $job->tries);

        $this->expectException(\RuntimeException::class);
        $job->handle(app(KanalPesan::class));
    }

    // ───────────────────── peralihan anak → dewasa ─────────────────────

    #[Test]
    public function peralihan_memakai_kanal_telepon_bila_tersedia_dan_antrean_kerja_bila_tidak(): void
    {
        $subjek = ConsentSubject::temukanAtauBuat($this->org->id, 'anak@contoh.id', [
            'subject_class' => KelasSubjek::ANAK,
            'transition_date' => now()->subDay()->toDateString(),
            'subject_own_channel' => '0812 3456 7890',
        ]);

        config(['messaging.sms.driver' => 'off']);
        $this->assertFalse(app(LayananPeralihan::class)->beralih($subjek));
        Queue::assertNotPushed(KirimPesanSingkatJob::class);
        Mail::assertNotQueued(PeralihanDewasaMail::class);
        $this->assertSame(KelasSubjek::TRANSISI_MENUNGGU, $subjek->fresh()->transition_state);

        // Kanal hidup → kirim ulang dari dashboard kini bisa.
        config(['messaging.sms.driver' => 'log']);
        app(LayananPeralihan::class)->kirimUlang($subjek->fresh());
        Queue::assertPushed(KirimPesanSingkatJob::class, function (KirimPesanSingkatJob $job) {
            $this->assertSame('+6281234567890', $job->pesan->tujuan);
            $this->assertSame(PesanSingkat::KONTEKS_TAUTAN_PERALIHAN, $job->pesan->konteks);
            $this->assertMatchesRegularExpression('~/peralihan/[A-Za-z0-9]{64}~', $job->pesan->teks);
            $this->assertStringContainsString('Bank Uji', $job->pesan->teks);
            $this->assertStringNotContainsString('anak@contoh.id', $job->pesan->teks);

            return true;
        });
        $this->assertNotNull($subjek->fresh()->transition_notified_at);
    }

    // ───────────────────── pengaturan platform ─────────────────────

    #[Test]
    public function pengaturan_messaging_tersimpan_rahasianya_tersamar_dan_menghidupkan_kanal_saat_boot(): void
    {
        Sanctum::actingAs($this->superadmin());

        $this->putJson('/api/platform-admin/settings/messaging', [
            'sms_driver' => 'http',
            'sms_http_url' => 'https://gw.contoh.id/send',
            'sms_http_method' => 'POST',
            'sms_http_headers' => '{"Authorization": "Bearer {secret}"}',
            'sms_http_secret' => 'RAHASIA-GW',
            'sms_http_body' => '{"target": "{to}", "message": "{message}"}',
            'sms_http_success_path' => 'status',
            'sms_http_success_equals' => 'success',
            'sms_to_format' => 'local',
            'sms_sender_name' => 'Privasimu',
        ])->assertOk()->assertJsonPath('section', 'messaging');

        // Rahasia tidak pernah kembali; yang lain tampil apa adanya.
        $index = $this->getJson('/api/platform-admin/settings')->assertOk();
        $this->assertSame('***', $index->json('messaging.sms_http_headers'));
        $this->assertSame('***', $index->json('messaging.sms_http_secret'));
        $this->assertSame('https://gw.contoh.id/send', $index->json('messaging.sms_http_url'));
        $this->assertStringNotContainsString('RAHASIA-GW', (string) $index->getContent());

        $mentah = (string) DB::table('system_settings')->where('key', 'messaging.sms_http_secret')->value('value');
        $this->assertStringNotContainsString('RAHASIA-GW', $mentah);

        // Validasi: driver http tanpa URL, JSON header rusak.
        $this->putJson('/api/platform-admin/settings/messaging', ['sms_driver' => 'http', 'sms_http_url' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['sms_http_url']);
        $this->putJson('/api/platform-admin/settings/messaging', ['sms_http_headers' => 'bukan json'])
            ->assertStatus(422)->assertJsonValidationErrors(['sms_http_headers']);

        // Sebelum boot ulang config lama masih berlaku (kanal mati).
        $this->assertFalse(KanalPesan::tersedia());

        // Boot ulang provider dengan cache di direktori sementara — supaya
        // uji tidak menulis bootstrap/cache/system_settings.json milik repo.
        $sementara = storage_path('framework/testing/bootstrap-'.uniqid());
        File::ensureDirectoryExists($sementara.'/cache');
        $asli = $this->app->bootstrapPath();
        try {
            $this->app->useBootstrapPath($sementara);
            (new SettingsServiceProvider($this->app))->boot();
        } finally {
            $this->app->useBootstrapPath($asli);
            File::deleteDirectory($sementara);
        }

        $this->assertSame('http', config('messaging.sms.driver'));
        $this->assertSame('https://gw.contoh.id/send', config('messaging.sms.http_url'));
        $this->assertSame('RAHASIA-GW', config('messaging.sms.http_secret'));
        $this->assertSame('{"Authorization": "Bearer {secret}"}', config('messaging.sms.http_headers'));
        $this->assertTrue(KanalPesan::tersedia());

        // Dan kini widget ditawari otp_phone.
        $this->assertContains('otp_phone', $this->metodeDiConfig());
    }

    #[Test]
    public function uji_kanal_pesan_dari_pengaturan_memakai_konfigurasi_formulir(): void
    {
        Sanctum::actingAs($this->superadmin());
        Http::fake(['gw.contoh.id/*' => Http::response(['status' => 'success', 'id' => 'MSG-7'], 200)]);

        $r = $this->postJson('/api/platform-admin/settings/messaging/test', [
            'test_to' => '0812 3456 7890',
            'sms_driver' => 'http',
            'sms_http_url' => 'https://gw.contoh.id/send',
            'sms_http_headers' => '{"Authorization": "Bearer {secret}"}',
            'sms_http_secret' => 'RAHASIA-UJI',
            'sms_http_body' => '{"target": "{to}", "message": "{message}"}',
            'sms_http_success_path' => 'status',
            'sms_http_success_equals' => 'success',
            'sms_to_format' => 'local',
        ]);
        $r->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reference', 'MSG-7')->assertJsonPath('driver', 'http');
        $this->assertStringNotContainsString('RAHASIA-UJI', (string) $r->getContent());
        $this->assertSame('•••••••••7890', $r->json('to'));

        Http::assertSent(fn (HttpRequest $req) => $req['target'] === '081234567890'
            && $req->hasHeader('Authorization', 'Bearer RAHASIA-UJI'));

        // Nomor tujuan tidak valid → ok=false, bukan 500.
        $this->postJson('/api/platform-admin/settings/messaging/test', ['test_to' => 'abc', 'sms_driver' => 'log'])
            ->assertOk()->assertJsonPath('ok', false);

        // driver off → tidak ada yang diuji.
        $this->postJson('/api/platform-admin/settings/messaging/test', ['test_to' => '081234567890', 'sms_driver' => 'off'])
            ->assertOk()->assertJsonPath('ok', false);

        // Rahasia yang dikirim "***" diambil dari yang tersimpan.
        SystemSetting::updateOrCreate(['key' => 'messaging.sms_http_secret'], [
            'value' => Crypt::encryptString('RAHASIA-TERSIMPAN'),
            'is_encrypted' => true, 'section' => 'messaging',
        ]);
        $this->postJson('/api/platform-admin/settings/messaging/test', [
            'test_to' => '081234567890', 'sms_driver' => 'http', 'sms_http_url' => 'https://gw.contoh.id/send',
            'sms_http_headers' => '{"Authorization": "Bearer {secret}"}', 'sms_http_secret' => '***',
            'sms_http_body' => '{"target": "{to}", "message": "{message}"}',
        ])->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn (HttpRequest $req) => $req->hasHeader('Authorization', 'Bearer RAHASIA-TERSIMPAN'));
    }

    #[Test]
    public function hanya_superadmin_yang_bisa_mengatur_kanal_pesan(): void
    {
        Sanctum::actingAs($this->pengguna());

        $this->putJson('/api/platform-admin/settings/messaging', ['sms_driver' => 'log'])->assertStatus(403);
        $this->postJson('/api/platform-admin/settings/messaging/test', ['test_to' => '081234567890'])->assertStatus(403);
    }

    // ───────────────────────── bantu ─────────────────────────

    /** @return array<string, string> */
    private function wali(string $kontak): array
    {
        return ['name' => 'Siti Rahayu', 'contact' => $kontak, 'relationship' => 'orang_tua'];
    }

    /** @param  array<string, mixed>  $tambahan */
    private function ajukan(array $tambahan = [])
    {
        return $this->postJson('/api/public/consent/guardian/request', array_merge([
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => $this->wali('siti@contoh.id'),
        ], $tambahan));
    }

    /** @return list<string> */
    private function metodeDiConfig(): array
    {
        return collect($this->getJson('/api/public/consent/config?collection_id='.$this->cp->collection_id)
            ->assertOk()->json('data.guardian_verification.methods'))->pluck('code')->all();
    }

    private function tautanDariPesan(): string
    {
        $token = null;
        Queue::assertPushed(KirimPesanSingkatJob::class, function (KirimPesanSingkatJob $job) use (&$token) {
            if (preg_match('~https?://\S+/wali/([A-Za-z0-9]{64})~', $job->pesan->teks, $m)) {
                $token = $m[1];
            }

            return true;
        });
        $this->assertNotNull($token, 'tautan tidak ditemukan di pesan');

        // Pesan menunjuk halaman Next.js; uji langsung ke endpoint API di baliknya.
        return '/api/public/consent/guardian/verify/'.$token;
    }

    private function pengguna(): User
    {
        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => 'peran-'.uniqid(),
                'slug' => 'role-'.uniqid(),
                'permissions' => ['consent_guardian:read', 'consent_guardian:write', 'settings:write'],
            ])->id,
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['org_id' => $this->org->id, 'role' => 'superadmin']);
    }
}
