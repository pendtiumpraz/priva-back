<?php

namespace Tests\Feature;

use App\Jobs\FireConsentWebhookJob;
use App\Mail\PeralihanDewasaMail;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Services\Consent\ConsentStateResolver;
use App\Services\Consent\GerbangWali;
use App\Services\Consent\LayananPeralihan;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Peralihan anak → dewasa — PP 33/2026 Pasal 38 ayat (8), Fase 5.
 *
 * Yang dijaga: pada hari genap 18 kewenangan wali BERAKHIR tetapi consent-nya
 * TIDAK hilang; subjek diberi tahu lewat kanal miliknya sendiri; yang tanpa
 * kanal masuk antrean kerja (bukan dicabut otomatis); membuka tautan tidak
 * memutuskan; menarik = baris ledger FALSE per titik yang dibaca resolver.
 */
class PeralihanAnakDewasaTest extends TestCase
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

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-uji-'.Str::random(6)]);
        $this->cp = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-001',
            'name' => 'Formulir Tabungan Pelajar',
            'kind' => ConsentCollectionPoint::KIND_APP,
            'webhook_url' => 'https://penerima.contoh.id/hook',
        ]);
        $this->item = ConsentItem::create([
            'collection_point_id' => $this->cp->id,
            'title' => 'Penawaran tabungan pelajar',
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);
    }

    // ───────────────────────── bantu ─────────────────────────

    /** Anak dengan wali terverifikasi dan satu consent yang dipayungi wali itu. */
    private function anak(string $penanda, string $tanggal, ?string $kanal = 'aku@contoh.id'): ConsentSubject
    {
        $s = ConsentSubject::temukanAtauBuat($this->org->id, $penanda, [
            'subject_class' => KelasSubjek::ANAK,
            'transition_date' => $tanggal,
            'subject_own_channel' => $kanal,
        ]);
        $w = Guardian::temukanAtauBuat($this->org->id, 'wali-'.Str::random(5).'@contoh.id', ['name' => 'Siti R.', 'relationship' => 'orang_tua']);
        $kw = GuardianConsent::create([
            'org_id' => $this->org->id,
            'consent_subject_id' => $s->id,
            'guardian_id' => $w->id,
            'collection_point_id' => $this->cp->id,
            'verified_at' => now()->subYear(),
            'verification_method_code' => 'otp_email',
            'verification_driver' => 'otp',
            'verification_confidence' => 'rendah',
        ]);
        ConsentLog::create([
            'org_id' => $this->org->id,
            'collection_id' => $this->cp->id,
            'user_identifier' => $penanda,
            'email' => $penanda,
            'consented_items' => [$this->item->id => true],
            'purpose_keys' => [$this->item->id],
            'guardian_consent_id' => $kw->id,
            'subject_class' => KelasSubjek::ANAK,
        ]);

        return $s->fresh();
    }

    private function tautanDariSurel(): string
    {
        $url = null;
        Mail::assertQueued(PeralihanDewasaMail::class, function (PeralihanDewasaMail $m) use (&$url) {
            $url = $m->decisionUrl;

            return true;
        });
        $this->assertNotNull($url);
        // Tautan surel menunjuk halaman Next.js /peralihan/{token}; uji langsung ke endpoint API-nya.
        $this->assertMatchesRegularExpression('~/peralihan/([A-Za-z0-9]{64})$~', $url);
        preg_match('~/peralihan/([A-Za-z0-9]{64})$~', $url, $m);

        return '/api/public/consent/transition/'.$m[1];
    }

    // ───────────────────────── antrean ─────────────────────────

    #[Test]
    public function antrean_mencabut_kewenangan_wali_dan_mengirim_tautan_ke_kanal_anak(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $this->assertSame(1, $s->waliBerwenang()->count());

        $this->artisan('consent:peralihan-anak')
            ->expectsOutputToContain('Diproses 1 · tautan terkirim 1 · tanpa kanal (antrean kerja) 0')
            ->assertSuccessful();

        $s->refresh();
        $this->assertSame(KelasSubjek::TRANSISI_MENUNGGU, $s->transition_state);
        $this->assertNotNull($s->transition_notified_at);
        // Kewenangan berakhir — consent-nya TIDAK.
        $this->assertSame(0, $s->waliBerwenang()->count());
        $this->assertSame('peralihan_dewasa', GuardianConsent::first()->revoke_reason);
        $this->assertSame(1, ConsentLog::count(), 'ledger tidak disentuh oleh peralihan');
        Mail::assertQueued(PeralihanDewasaMail::class, fn ($m) => $m->hasTo('aku@contoh.id'));

        // Idempoten: hari berikutnya tidak memproses ulang.
        $this->artisan('consent:peralihan-anak')
            ->expectsOutputToContain('Diproses 0')
            ->assertSuccessful();
        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function anak_yang_belum_18_tidak_disentuh(): void
    {
        $s = $this->anak('anak@contoh.id', now()->addYear()->toDateString());

        $this->artisan('consent:peralihan-anak')->expectsOutputToContain('Diproses 0')->assertSuccessful();

        $this->assertNull($s->fresh()->transition_state);
        $this->assertSame(1, $s->waliBerwenang()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function tanpa_kanal_sendiri_masuk_antrean_kerja_bukan_dicabut_otomatis(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString(), null);

        $this->artisan('consent:peralihan-anak')
            ->expectsOutputToContain('tautan terkirim 0 · tanpa kanal (antrean kerja) 1')
            ->assertSuccessful();

        $s->refresh();
        $this->assertSame(KelasSubjek::TRANSISI_MENUNGGU, $s->transition_state);
        $this->assertNull($s->transition_notified_at);
        $this->assertNull($s->transition_confirmed_at);
        $this->assertSame(0, $s->waliBerwenang()->count());
        // Consent tetap berlaku — resolver masih membaca "granted".
        $keadaan = app(ConsentStateResolver::class)->resolve($this->org->id, 'anak@contoh.id', [[$this->cp->id, $this->item->id]]);
        $this->assertSame(ConsentStateResolver::GRANTED, $keadaan[$this->cp->id.'|'.$this->item->id]);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function dry_run_tidak_mengubah_apa_pun(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString());

        $this->artisan('consent:peralihan-anak', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Diproses 1')
            ->assertSuccessful();

        $this->assertNull($s->fresh()->transition_state);
        $this->assertSame(1, $s->waliBerwenang()->count());
        Mail::assertNothingQueued();
    }

    // ───────────────────────── tautan keputusan ─────────────────────────

    #[Test]
    public function membuka_tautan_menampilkan_consent_yang_dulu_diberikan_wali_tanpa_memutuskan(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $this->artisan('consent:peralihan-anak')->assertSuccessful();
        $url = $this->tautanDariSurel();

        $r = $this->getJson($url)->assertOk()
            ->assertJsonPath('subject_label', 'anak@contoh.id')
            ->assertJsonPath('state', KelasSubjek::TRANSISI_MENUNGGU)
            ->assertJsonPath('already_decided', false)
            ->assertJsonPath('points.0.name', 'Formulir Tabungan Pelajar')
            ->assertJsonPath('points.0.purposes.0', 'Penawaran tabungan pelajar');

        $this->assertStringNotContainsString('transition_token_hash', $r->getContent());
        $this->assertNull($s->fresh()->transition_confirmed_at);
        $this->assertSame(KelasSubjek::ANAK, $s->fresh()->subject_class);
        $this->getJson($url)->assertOk();
    }

    #[Test]
    public function melanjutkan_menjadikan_dewasa_dan_wali_tidak_bisa_lagi_bertindak(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $kw = GuardianConsent::first();
        $this->artisan('consent:peralihan-anak')->assertSuccessful();
        $url = $this->tautanDariSurel();

        $this->postJson($url.'/confirm')->assertOk()
            ->assertJsonPath('state', KelasSubjek::TRANSISI_DIKONFIRMASI)
            ->assertJsonPath('subject_class', KelasSubjek::DEWASA);

        $s->refresh();
        $this->assertSame(KelasSubjek::DEWASA, $s->subject_class);
        $this->assertNotNull($s->transition_confirmed_at);
        $this->assertNull($s->transition_token_hash);
        $this->assertSame(1, ConsentLog::count(), 'melanjutkan tidak menulis ledger — consent-nya memang tetap');

        // Tautan sekali pakai.
        $this->postJson($url.'/withdraw')->assertStatus(404);

        // Kewenangan lama tidak bisa dipakai lagi untuk menangkap atas nama subjek.
        $this->postJson('/api/public/consent', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => false],
            'subject_class' => 'anak',
            'guardian_consent_id' => $kw->id,
        ])->assertStatus(422)->assertJsonPath('code', GerbangWali::WALI_TIDAK_SAH);

        // Sebagai dewasa ia bertindak sendiri.
        $this->postJson('/api/public/consent', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => [$this->item->id => false],
            'subject_class' => 'dewasa',
        ])->assertStatus(201);
    }

    #[Test]
    public function menarik_menulis_penarikan_ke_ledger_per_titik_dan_resolver_membacanya(): void
    {
        $s = $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $this->artisan('consent:peralihan-anak')->assertSuccessful();
        $url = $this->tautanDariSurel();

        $this->postJson($url.'/withdraw')->assertOk()
            ->assertJsonPath('state', KelasSubjek::TRANSISI_DITARIK)
            ->assertJsonPath('subject_class', KelasSubjek::DEWASA);

        $this->assertSame(2, ConsentLog::count());
        $penarikan = ConsentLog::orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame('transition_withdraw', $penarikan->source_form);
        $this->assertSame(KelasSubjek::DEWASA, $penarikan->subject_class);
        $this->assertNull($penarikan->guardian_consent_id);
        $this->assertSame([$this->item->id => false], $penarikan->consented_items);

        $keadaan = app(ConsentStateResolver::class)->resolve($this->org->id, 'anak@contoh.id', [[$this->cp->id, $this->item->id]]);
        $this->assertSame(ConsentStateResolver::NOT_GRANTED, $keadaan[$this->cp->id.'|'.$this->item->id]);

        Queue::assertPushed(FireConsentWebhookJob::class, fn ($j) => ($j->payload['source'] ?? null) === 'transition_withdraw'
            && ($j->payload['transition'] ?? null) === KelasSubjek::TRANSISI_DITARIK);

        $s->refresh();
        $this->assertSame(KelasSubjek::TRANSISI_DITARIK, $s->transition_state);
        $this->assertNotNull($s->transition_confirmed_at);
    }

    #[Test]
    public function tautan_kedaluwarsa_ditolak(): void
    {
        $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $this->artisan('consent:peralihan-anak')->assertSuccessful();
        $url = $this->tautanDariSurel();

        $this->travel(LayananPeralihan::MASA_BERLAKU_HARI + 1)->days();

        $this->getJson($url)->assertStatus(410)->assertJsonPath('code', LayananPeralihan::TOKEN_KEDALUWARSA);
        $this->postJson($url.'/withdraw')->assertStatus(410);
        $this->assertSame(1, ConsentLog::count());
    }

    #[Test]
    public function halaman_html_untuk_peramban_subjek(): void
    {
        $this->anak('anak@contoh.id', now()->subDay()->toDateString());
        $this->artisan('consent:peralihan-anak')->assertSuccessful();
        $url = $this->tautanDariSurel();

        $this->get($url, ['Accept' => 'text/html'])->assertOk()
            ->assertSee('Lanjutkan persetujuan')
            ->assertSee('Tarik semua persetujuan')
            ->assertSee('Penawaran tabungan pelajar');

        $this->post($url.'/confirm', [], ['Accept' => 'text/html'])->assertOk()->assertSee('Persetujuan dilanjutkan');
    }
}
