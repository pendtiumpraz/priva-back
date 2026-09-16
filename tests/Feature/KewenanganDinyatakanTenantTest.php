<?php

namespace Tests\Feature;

use App\Jobs\KirimPesanSingkatJob;
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
use App\Services\Consent\GerbangWali;
use App\Services\Consent\LayananWali;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pernyataan tenant — `POST /v1/consent/guardian/assert` (Fase 9).
 *
 * Tenant yang sudah memverifikasi walinya sendiri menyatakannya lewat
 * Partner API. Yang dijaga: hanya metode ber-driver `tenant_asserted` milik
 * tenant; kewenangan lahir terverifikasi dengan keyakinan yang TENANT
 * nyatakan (bukan kami); tidak menimpa verifikasi yang lebih kuat; tidak
 * pernah lewat widget; kewenangannya diterima gerbang tangkap seperti yang lain.
 */
class KewenanganDinyatakanTenantTest extends TestCase
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

        $this->org = Organization::factory()->create(['name' => 'Bank Uji']);
        $this->cp = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-001',
            'name' => 'Formulir Tabungan Pelajar',
            'kind' => ConsentCollectionPoint::KIND_APP,
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

    #[Test]
    public function pernyataan_tenant_melahirkan_kewenangan_terverifikasi_yang_diterima_gerbang_tangkap(): void
    {
        $this->metodeTenant();

        $r = $this->nyatakan(['reference' => 'KYC-2026-0001', 'verified_at' => '2026-09-01T09:00:00+07:00']);
        $r->assertStatus(201)
            ->assertJsonPath('status', 'terverifikasi')
            ->assertJsonPath('verification.driver', 'tenant_asserted')
            ->assertJsonPath('verification.method_code', 'kyc_bank')
            ->assertJsonPath('verification.confidence', 'sedang')
            ->assertJsonPath('verification.reference', 'KYC-2026-0001');
        $id = (string) $r->json('guardian_consent_id');

        $kw = GuardianConsent::withoutGlobalScope('org')->sole();
        $this->assertSame($id, $kw->id);
        $this->assertSame('2026-09-01 02:00:00', $kw->verified_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($kw->pending_capture);
        $this->assertNull($kw->verification_token_hash);

        // Tidak ada surel, tidak ada pesan, belum ada ledger.
        Mail::assertNotQueued(GuardianVerificationMail::class);
        Queue::assertNotPushed(KirimPesanSingkatJob::class);
        $this->assertSame(0, ConsentLog::count());

        $audit = AuditLog::where('action', 'guardian_consent.asserted')->sole();
        $this->assertSame('KYC-2026-0001', ((array) $audit->changes)['verification_reference']);

        // Kewenangan ini diterima gerbang tangkap — jalur yang sama dengan yang lain.
        $this->v1('POST', '/api/v1/consent/capture', [
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => true],
            'subject_class' => 'anak',
            'guardian_consent_id' => $id,
        ])->assertStatus(201);

        $log = ConsentLog::sole();
        $this->assertSame($id, $log->guardian_consent_id);
        $this->assertSame(KelasSubjek::ANAK, $log->subject_class);

        // …tetapi bukan untuk anak lain.
        $this->v1('POST', '/api/v1/consent/capture', [
            'user_identifier' => 'anak-lain@contoh.id',
            'consented_items' => [$this->item->id => true],
            'subject_class' => 'anak',
            'guardian_consent_id' => $id,
        ])->assertStatus(422)->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH);
    }

    #[Test]
    public function hanya_metode_ber_driver_tenant_asserted_milik_tenant_ini_yang_diterima(): void
    {
        // Belum ada metode.
        $this->nyatakan()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Metode kuat (Dukcapil) bukan pernyataan tenant.
        VerificationMethod::create([
            'org_id' => $this->org->id, 'code' => 'dukcapil_bank', 'label' => 'Dukcapil', 'driver' => 'dukcapil',
            'confidence' => 'tinggi', 'is_active' => true, 'config' => ['endpoint' => 'https://x', 'match_all' => [['path' => 'ok', 'equals' => true]]],
        ]);
        $this->nyatakan(['method_code' => 'dukcapil_bank'])->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Bawaan platform otp_email juga bukan.
        $this->nyatakan(['method_code' => 'otp_email'])->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Nonaktif.
        $m = $this->metodeTenant(['is_active' => false]);
        $this->nyatakan()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        // Milik tenant lain.
        $m->update(['is_active' => true, 'org_id' => Organization::factory()->create()->id]);
        $this->nyatakan()->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);

        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
        $this->assertSame(0, Guardian::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function verified_at_di_masa_depan_dan_kontak_tidak_valid_ditolak(): void
    {
        $this->metodeTenant();

        $this->nyatakan(['verified_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['verified_at']);

        $this->nyatakan(['guardian' => ['name' => 'Siti Rahayu', 'contact' => '0215551234', 'relationship' => 'orang_tua']])
            ->assertStatus(422)->assertJsonPath('code', LayananWali::KONTAK_TIDAK_VALID);

        $this->assertSame(0, GuardianConsent::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function pernyataan_tenant_tidak_menurunkan_verifikasi_dukcapil_yang_sudah_ada(): void
    {
        $subjek = ConsentSubject::temukanAtauBuat($this->org->id, 'anak@contoh.id', ['subject_class' => KelasSubjek::ANAK]);
        $wali = Guardian::temukanAtauBuat($this->org->id, 'siti@contoh.id', ['name' => 'Siti Rahayu', 'relationship' => 'orang_tua']);
        $lama = GuardianConsent::create([
            'org_id' => $this->org->id,
            'consent_subject_id' => $subjek->id,
            'guardian_id' => $wali->id,
            'verified_at' => now()->subDay(),
            'verification_method_code' => 'dukcapil_bank',
            'verification_driver' => 'dukcapil',
            'verification_confidence' => 'tinggi',
            'verification_reference' => 'TRX-1',
        ]);

        $this->metodeTenant();
        $this->nyatakan(['reference' => 'KYC-9'])->assertStatus(201)->assertJsonPath('guardian_consent_id', $lama->id);

        $this->assertSame(1, GuardianConsent::withoutGlobalScope('org')->count());
        $kw = $lama->fresh();
        $this->assertSame('tinggi', $kw->verification_confidence);
        $this->assertSame('dukcapil', $kw->verification_driver);
        $this->assertSame('TRX-1', $kw->verification_reference);

        // Sebaliknya, pernyataan tenant (sedang) menaikkan kewenangan surel (rendah).
        $lama->forceFill(['verification_method_code' => 'otp_email', 'verification_driver' => 'otp', 'verification_confidence' => 'rendah', 'verification_reference' => null])->save();
        $this->nyatakan(['reference' => 'KYC-9'])->assertStatus(201);
        $kw = $lama->fresh();
        $this->assertSame('sedang', $kw->verification_confidence);
        $this->assertSame('tenant_asserted', $kw->verification_driver);
        $this->assertSame('KYC-9', $kw->verification_reference);
    }

    #[Test]
    public function tidak_ada_jalur_widget_dan_widget_tidak_ditawari_metode_pernyataan_tenant(): void
    {
        $this->metodeTenant();

        // Tidak ada endpoint publik untuk pernyataan — hanya Partner API ber-HMAC.
        $this->postJson('/api/public/consent/guardian/assert', [])->assertStatus(404);

        // Widget tidak pernah ditawari metode ini.
        $kode = collect($this->getJson('/api/public/consent/config?collection_id='.$this->cp->collection_id)
            ->assertOk()->json('data.guardian_verification.methods'))->pluck('code')->all();
        $this->assertNotContains('kyc_bank', $kode);

        // Dan jalur widget guardian/request tidak menerima method_code pernyataan tenant sebagai verifikasi kuat.
        $this->postJson('/api/public/consent/guardian/request', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
            'verification' => ['method_code' => 'kyc_bank', 'nik' => '3175012001900004', 'birth_date' => '1990-01-20'],
        ])->assertStatus(422)->assertJsonPath('code', LayananWali::METODE_TIDAK_DIKENAL);
    }

    #[Test]
    public function katalog_menerima_driver_tenant_asserted_dengan_keyakinan_bawaan_sedang(): void
    {
        Sanctum::actingAs($this->pengguna());

        $r = $this->postJson('/api/verification-methods', ['code' => 'kyc_bank', 'label' => 'KYC internal Bank Uji', 'driver' => 'tenant_asserted']);
        $r->assertStatus(201)
            ->assertJsonPath('data.confidence', 'sedang')
            ->assertJsonPath('data.strong', false)
            ->assertJsonPath('data.runnable', false)
            ->assertJsonPath('data.config', null);
        $id = $r->json('data.id');

        $this->assertContains('tenant_asserted', $this->getJson('/api/verification-methods')->json('drivers'));

        // Tidak ada yang bisa diuji: kami tidak memeriksa apa pun.
        $this->postJson('/api/verification-methods/'.$id.'/test', ['nik' => '3175012001900004', 'name' => 'Siti', 'birth_date' => '1990-01-20'])
            ->assertStatus(422)->assertJsonPath('code', 'BUKAN_METODE_KUAT');
    }

    // ───────────────────────── bantu ─────────────────────────

    /** @param  array<string, mixed>  $override */
    private function metodeTenant(array $override = []): VerificationMethod
    {
        return VerificationMethod::create(array_merge([
            'org_id' => $this->org->id,
            'code' => 'kyc_bank',
            'label' => 'KYC internal Bank Uji',
            'driver' => VerificationMethod::DRIVER_TENANT,
            'confidence' => 'sedang',
            'is_active' => true,
            'config' => null,
        ], $override));
    }

    /** @param  array<string, mixed>  $tambahan */
    private function nyatakan(array $tambahan = []): TestResponse
    {
        return $this->v1('POST', '/api/v1/consent/guardian/assert', array_merge([
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
            'method_code' => 'kyc_bank',
        ], $tambahan));
    }

    /** @param  array<string, mixed>  $payload */
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

    private function pengguna(): User
    {
        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => 'peran-'.Str::random(6),
                'slug' => 'role-'.Str::random(6),
                'permissions' => ['consent:read', 'consent:write'],
            ])->id,
        ]);
    }
}
