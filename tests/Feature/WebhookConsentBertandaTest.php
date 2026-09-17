<?php

namespace Tests\Feature;

use App\Jobs\FireConsentWebhookJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Webhook consent DITANDATANGANI — kontrak yang dijanjikan panduan integrasi
 * di dashboard (tab Integrasi → Webhook) dan sama dengan webhook DSR:
 *
 *   X-Privasimu-Signature: sha256=<hmac_sha256(rawBody, embed_token)>
 *
 * Yang dijaga: tanda tangan cocok dengan BYTE yang dikirim, kuncinya
 * `embed_token` titik itu (bukan titik lain), bentuk payload tidak berubah,
 * id kiriman tetap sama di setiap percobaan ulang, dan ketiga pintu tangkap
 * menyerahkan kunci titiknya sendiri.
 */
class WebhookConsentBertandaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function badan_ditandatangani_dengan_embed_token_dan_payload_tetap_datar(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $payload = [
            'event' => 'consent.captured',
            'collection_id' => 'CNT-2026-001',
            'user_identifier' => 'subjek@contoh.id',
            'consented_items' => ['item-1' => true],
            'consented_purposes' => ['Penawaran & promo'],
        ];

        $job = new FireConsentWebhookJob('https://penerima.contoh.id/hook', 'CNT-2026-001', $payload, 'rahasia-embed-token');
        $job->handle();

        Http::assertSent(function (Request $request) use ($job) {
            $body = $request->body();
            $this->assertSame('https://penerima.contoh.id/hook', $request->url());
            $this->assertSame(
                'sha256='.hash_hmac('sha256', $body, 'rahasia-embed-token'),
                $request->header('X-Privasimu-Signature')[0] ?? null,
                'tanda tangan harus dihitung atas byte yang benar-benar dikirim',
            );
            $this->assertSame('consent.captured', $request->header('X-Privasimu-Event')[0] ?? null);
            $this->assertSame($job->deliveryId, $request->header('X-Privasimu-Delivery')[0] ?? null);
            $this->assertMatchesRegularExpression('/^\d{10}$/', $request->header('X-Privasimu-Timestamp')[0] ?? '');

            // Bentuk payload TIDAK dibungkus — integrasi yang sudah berjalan membaca
            // `event`, `user_identifier`, `consented_items` di tingkat teratas.
            $terkirim = json_decode($body, true);
            $this->assertSame('subjek@contoh.id', $terkirim['user_identifier']);
            $this->assertSame(['item-1' => true], $terkirim['consented_items']);
            $this->assertStringContainsString('Penawaran & promo', $body, 'unicode/garis miring tidak di-escape');

            return true;
        });
    }

    #[Test]
    public function id_kiriman_ditetapkan_saat_dispatch_sehingga_sama_di_setiap_percobaan_ulang(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $job = new FireConsentWebhookJob('https://penerima.contoh.id/hook', 'CNT-1', ['event' => 'consent.captured'], 'k');
        $this->assertTrue(Str::isUuid((string) $job->deliveryId));

        /** @var FireConsentWebhookJob $ulang */
        $ulang = unserialize(serialize($job));
        $job->handle();
        $ulang->handle();

        $id = [];
        Http::assertSent(function (Request $request) use (&$id) {
            $id[] = $request->header('X-Privasimu-Delivery')[0] ?? null;

            return true;
        });
        $this->assertCount(2, $id);
        $this->assertSame($id[0], $id[1]);
    }

    #[Test]
    public function tanpa_kunci_tidak_ada_header_tanda_tangan_palsu(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        (new FireConsentWebhookJob('https://penerima.contoh.id/hook', 'CNT-1', ['event' => 'consent.captured']))->handle();

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Privasimu-Signature'));
    }

    #[Test]
    public function penerima_yang_gagal_membuat_job_diulang(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->expectException(\RuntimeException::class);
        (new FireConsentWebhookJob('https://penerima.contoh.id/hook', 'CNT-1', ['event' => 'consent.captured'], 'k'))->handle();
    }

    #[Test]
    public function pintu_widget_dan_partner_api_menyerahkan_embed_token_titiknya_sendiri(): void
    {
        Queue::fake();
        $org = Organization::create(['name' => 'Toko Daring', 'slug' => 'toko-'.Str::random(6)]);
        $cp = ConsentCollectionPoint::create([
            'org_id' => $org->id, 'collection_id' => 'CNT-2026-501', 'name' => 'Aplikasi Toko',
            'kind' => ConsentCollectionPoint::KIND_APP, 'webhook_url' => 'https://penerima.contoh.id/hook',
        ]);
        $lain = ConsentCollectionPoint::create([
            'org_id' => $org->id, 'collection_id' => 'CNT-2026-502', 'name' => 'Aplikasi Lain',
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);
        [$clientKey, $serverKey] = ConsentCollectionPoint::generateApiKeyPair();
        $cp->update(['client_key' => $clientKey, 'server_key' => $serverKey, 'auth_methods' => ['widget' => true, 'api_key' => true]]);
        $item = ConsentItem::create(['collection_point_id' => $cp->id, 'title' => 'Promo', 'category' => 'other', 'version' => '1.0', 'is_active' => true]);

        $this->assertNotEmpty($cp->embed_token);
        $this->assertNotSame($cp->embed_token, $lain->embed_token);
        $this->assertSame($cp->embed_token, $cp->kunciTandaWebhook());

        // Pintu widget.
        $this->postJson('/api/public/consent/capture', [
            'collection_id' => $cp->embed_token, 'user_identifier' => 'a@contoh.id', 'consented_items' => [$item->id => true],
        ])->assertStatus(201);

        // Pintu Partner API (server-to-server).
        $body = (string) json_encode(['user_identifier' => 'b@contoh.id', 'consented_items' => [$item->id => true], 'channel' => 'backend']);
        $this->call('POST', '/api/v1/consent/capture', [], [], [], [
            'HTTP_X_PRIVASIMU_CLIENT_KEY' => $clientKey,
            'HTTP_X_PRIVASIMU_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $serverKey),
            'HTTP_X_PRIVASIMU_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(201);

        Queue::assertPushed(FireConsentWebhookJob::class, 2);
        Queue::assertPushed(FireConsentWebhookJob::class, fn (FireConsentWebhookJob $job) => $job->signingSecret === $cp->embed_token
            && $job->payload['user_identifier'] === 'a@contoh.id');
        Queue::assertPushed(FireConsentWebhookJob::class, fn (FireConsentWebhookJob $job) => $job->signingSecret === $cp->embed_token
            && $job->payload['user_identifier'] === 'b@contoh.id' && ($job->payload['source'] ?? null) === 'partner_api');
    }

    #[Test]
    public function titik_lama_tanpa_embed_token_memakai_collection_id_sebagai_kunci(): void
    {
        $org = Organization::create(['name' => 'Arsip', 'slug' => 'arsip-'.Str::random(6)]);
        $cp = ConsentCollectionPoint::create(['org_id' => $org->id, 'collection_id' => 'CNT-2019-001', 'name' => 'Titik Lama']);
        $cp->forceFill(['embed_token' => null])->saveQuietly();

        $this->assertSame('CNT-2019-001', $cp->fresh()->kunciTandaWebhook());
    }
}
