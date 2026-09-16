<?php

namespace Tests\Feature;

use App\Jobs\KirimPesanSingkatJob;
use App\Mail\GuardianVerificationMail;
use App\Mail\PeralihanDewasaMail;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Organization;
use App\Services\Consent\LayananPeralihan;
use App\Services\Consent\TautanPublik;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tautan yang dibuka manusia menunjuk HALAMAN Next.js — /wali/{token} dan
 * /peralihan/{token} — bukan endpoint API. Salah host = 404 di tangan wali,
 * tanpa satu pun galat di sisi kita (lihat App\Support\FrontendUrl).
 */
class TautanHalamanFrontendTest extends TestCase
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
        config(['app.frontend_url' => 'https://app.contoh.id/']);

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

    #[Test]
    public function tautan_surel_wali_menunjuk_halaman_frontend_dan_tokennya_sah_di_api(): void
    {
        $this->postJson('/api/public/consent/guardian/request', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => 'siti@contoh.id', 'relationship' => 'orang_tua'],
        ])->assertStatus(202);

        $url = null;
        Mail::assertQueued(GuardianVerificationMail::class, function (GuardianVerificationMail $m) use (&$url) {
            $url = $m->verifyUrl;

            return true;
        });

        $this->assertMatchesRegularExpression('~^https://app\.contoh\.id/wali/[A-Za-z0-9]{64}$~', (string) $url);
        $this->assertStringNotContainsString('/api/', (string) $url);

        // Token yang sama diterima endpoint API yang dipanggil halaman itu.
        $token = substr((string) $url, -64);
        $this->getJson('/api/public/consent/guardian/verify/'.$token)->assertOk()->assertJsonPath('has_pending', true);
        $this->postJson('/api/public/consent/guardian/verify/'.$token)->assertOk();
        $this->assertSame(1, ConsentLog::count());
    }

    #[Test]
    public function tautan_pesan_singkat_wali_juga_menunjuk_halaman_frontend(): void
    {
        config(['messaging.sms.driver' => 'log']);

        $this->postJson('/api/public/consent/guardian/request', [
            'collection_id' => $this->cp->collection_id,
            'user_identifier' => 'anak@contoh.id',
            'subject_class' => 'anak',
            'consented_items' => [$this->item->id => true],
            'guardian' => ['name' => 'Siti Rahayu', 'contact' => '081234567890', 'relationship' => 'orang_tua'],
        ])->assertStatus(202);

        Queue::assertPushed(KirimPesanSingkatJob::class, function (KirimPesanSingkatJob $job) {
            $this->assertMatchesRegularExpression('~https://app\.contoh\.id/wali/[A-Za-z0-9]{64}~', $job->pesan->teks);
            $this->assertStringNotContainsString('/api/', $job->pesan->teks);

            return true;
        });
    }

    #[Test]
    public function tautan_peralihan_menunjuk_halaman_frontend(): void
    {
        $subjek = ConsentSubject::temukanAtauBuat($this->org->id, 'anak@contoh.id', [
            'subject_class' => KelasSubjek::ANAK,
            'transition_date' => now()->subDay()->toDateString(),
            'subject_own_channel' => 'anak@contoh.id',
        ]);

        $this->assertTrue(app(LayananPeralihan::class)->beralih($subjek));

        Mail::assertQueued(PeralihanDewasaMail::class, function (PeralihanDewasaMail $m) {
            $this->assertMatchesRegularExpression('~^https://app\.contoh\.id/peralihan/[A-Za-z0-9]{64}$~', $m->decisionUrl);
            $token = substr($m->decisionUrl, -64);
            $this->getJson('/api/public/consent/transition/'.$token)->assertOk()->assertJsonPath('already_decided', false);

            return true;
        });
    }

    #[Test]
    public function pembentuk_tautan_memakai_basis_frontend_tanpa_garis_miring_ganda(): void
    {
        $this->assertSame('https://app.contoh.id/wali/abc', TautanPublik::wali('abc'));
        $this->assertSame('https://app.contoh.id/peralihan/abc', TautanPublik::peralihan('abc'));
    }
}
