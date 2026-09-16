<?php

namespace Tests\Feature;

use App\Jobs\FireConsentWebhookJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\Organization;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bukti penyajian yang dapat diakses — PP 33/2026 Pasal 39 ayat (3).
 *
 * Skrip embed modul aksesibilitas mengirim `accessibility` (format yang
 * dipakai, didampingi, hubungan pendamping) bersama penangkapan. Yang dijaga:
 * disimpan HANYA untuk subjek disabilitas, dari pintu widget maupun Partner
 * API, ikut ke webhook, kode format yang tidak dikenal ditolak, dan tidak
 * ada nama pendamping yang ikut tersimpan.
 */
class TangkapAksesibilitasTest extends TestCase
{
    use RefreshDatabase;

    private ConsentCollectionPoint $cp;

    private ConsentItem $item;

    private string $clientKey;

    private string $serverKey;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $org = Organization::create(['name' => 'Layanan Inklusif', 'slug' => 'inklusif-'.Str::random(6)]);
        $this->cp = ConsentCollectionPoint::create([
            'org_id' => $org->id,
            'collection_id' => 'CNT-2026-777',
            'name' => 'Layanan Inklusif',
            'kind' => ConsentCollectionPoint::KIND_APP,
            'owner_module' => 'consent_accessibility',
            'settings' => ['guardian_mode' => true, 'subject_class_default' => 'disabilitas'],
            'webhook_url' => 'https://penerima.contoh.id/hook',
        ]);
        [$this->clientKey, $this->serverKey] = ConsentCollectionPoint::generateApiKeyPair();
        $this->cp->update(['client_key' => $this->clientKey, 'server_key' => $this->serverKey, 'auth_methods' => ['widget' => true, 'api_key' => true]]);
        $this->item = ConsentItem::create(['collection_point_id' => $this->cp->id, 'title' => 'Layanan pendampingan', 'category' => 'other', 'version' => '1.0', 'is_active' => true]);
    }

    #[Test]
    public function bukti_penyajian_disimpan_untuk_subjek_disabilitas_dan_ikut_ke_webhook(): void
    {
        $this->tangkap([
            'subject_class' => 'disabilitas',
            'accessibility' => ['formats_used' => ['tts', 'teks_besar', 'tts'], 'assisted' => true, 'companion_relationship' => 'pendamping'],
        ])->assertStatus(201);

        $log = ConsentLog::first();
        $this->assertSame(KelasSubjek::DISABILITAS, $log->subject_class);
        $this->assertSame(['tts', 'teks_besar'], $log->accessibility_meta['formats_used'], 'duplikat dibuang, urutan dijaga');
        $this->assertTrue($log->accessibility_meta['assisted']);
        $this->assertSame('pendamping', $log->accessibility_meta['companion_relationship']);
        $this->assertArrayNotHasKey('companion_name', $log->accessibility_meta);

        Queue::assertPushed(FireConsentWebhookJob::class, function (FireConsentWebhookJob $job) {
            $p = $job->payload;

            return $p['subject_class'] === 'disabilitas' && $p['accessibility']['formats_used'] === ['tts', 'teks_besar'];
        });
    }

    #[Test]
    public function hubungan_pendamping_saja_sudah_berarti_didampingi(): void
    {
        $this->tangkap(['subject_class' => 'disabilitas', 'accessibility' => ['companion_relationship' => 'orang tua']])->assertStatus(201);

        $meta = ConsentLog::first()->accessibility_meta;
        $this->assertTrue($meta['assisted']);
        $this->assertSame([], $meta['formats_used']);
    }

    #[Test]
    public function untuk_dewasa_bukti_penyajian_diabaikan(): void
    {
        $this->tangkap(['subject_class' => 'dewasa', 'accessibility' => ['formats_used' => ['tts']]])->assertStatus(201);
        $this->assertNull(ConsentLog::first()->accessibility_meta);

        $this->tangkap(['user_identifier' => 'lain@contoh.id', 'subject_class' => 'disabilitas'])->assertStatus(201);
        $this->assertNull(ConsentLog::where('user_identifier', 'lain@contoh.id')->first()->accessibility_meta, 'tanpa masukan = null, bukan larik kosong');
    }

    #[Test]
    public function kode_format_yang_tidak_dikenal_ditolak(): void
    {
        $this->tangkap(['subject_class' => 'disabilitas', 'accessibility' => ['formats_used' => ['telepati']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accessibility.formats_used.0']);
        $this->assertSame(0, ConsentLog::count());
    }

    #[Test]
    public function partner_api_menyimpan_bukti_yang_sama(): void
    {
        $body = (string) json_encode([
            'user_identifier' => 'subjek@contoh.id',
            'consented_items' => [$this->item->id => true],
            'subject_class' => 'disabilitas',
            'channel' => 'mobile',
            'accessibility' => ['formats_used' => ['pembaca_layar'], 'assisted' => false],
        ]);
        $r = $this->call('POST', '/api/v1/consent/capture', [], [], [], [
            'HTTP_X_PRIVASIMU_CLIENT_KEY' => $this->clientKey,
            'HTTP_X_PRIVASIMU_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->serverKey),
            'HTTP_X_PRIVASIMU_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
        $r->assertStatus(201);

        $meta = ConsentLog::first()->accessibility_meta;
        $this->assertSame(['pembaca_layar'], $meta['formats_used']);
        $this->assertFalse($meta['assisted']);
        $this->assertNull($meta['companion_relationship']);
    }

    private function tangkap(array $tambahan = []): TestResponse
    {
        return $this->postJson('/api/public/consent/capture', array_merge([
            'collection_id' => $this->cp->embed_token,
            'user_identifier' => 'subjek@contoh.id',
            'consented_items' => [$this->item->id => true],
        ], $tambahan));
    }
}
