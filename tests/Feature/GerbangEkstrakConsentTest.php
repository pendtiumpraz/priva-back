<?php

namespace Tests\Feature;

use App\Jobs\PushExtractToCrmJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentRule;
use App\Models\ConsentRuleCondition;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use App\Models\CrmCredential;
use App\Models\ExtractRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consent\ConsentStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gerbang pada jalur ekstrak massal — unduhan CSV dan dorongan CRM.
 *
 * Jalur inilah yang paling mungkin memindahkan banyak data sekaligus, dan
 * dulu ia satu-satunya jalan keluar yang tidak melewati aturan sama sekali.
 */
class GerbangEkstrakConsentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ConsentCollectionPoint $cpDijaga;

    private ConsentCollectionPoint $cpBebas;

    private ConsentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-uji-'.Str::random(6)]);
        $this->admin = User::factory()->create(['org_id' => $this->org->id, 'role' => 'admin']);

        $this->cpDijaga = $this->titik('CNT-2026-001', 'Formulir Dijaga');
        $this->cpBebas = $this->titik('CNT-2026-002', 'Formulir Bebas');

        $this->item = ConsentItem::create([
            'collection_point_id' => $this->cpDijaga->id,
            'title' => 'Penawaran syariah',
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);

        $this->log($this->cpDijaga, 'alice@contoh.id', [$this->item->id => true]);
        $this->log($this->cpBebas, 'bob@contoh.id', []);
    }

    // ------------------------------------------------- kompatibilitas mundur

    public function test_tanpa_penjagaan_semua_baris_tetap_keluar(): void
    {
        Sanctum::actingAs($this->admin);

        $csv = $this->unduhCsv();

        $this->assertStringContainsString('alice@contoh.id', $csv);
        $this->assertStringContainsString('bob@contoh.id', $csv);
        $this->assertNull(ExtractRun::first()->gate_summary);
    }

    // --------------------------------------------------------- penjagaan CSV

    public function test_csv_tidak_memuat_baris_yang_ditahan(): void
    {
        $this->setPenahan();
        Sanctum::actingAs($this->admin);

        $csv = $this->unduhCsv();

        $this->assertStringNotContainsString('alice@contoh.id', $csv);
        // Titik yang tidak dijaga tidak boleh ikut terpotong.
        $this->assertStringContainsString('bob@contoh.id', $csv);
    }

    public function test_ringkasan_penjagaan_tersimpan_di_jalan_ekstrak(): void
    {
        $set = $this->setPenahan();
        Sanctum::actingAs($this->admin);

        $this->unduhCsv();

        $ringkasan = ExtractRun::first()->gate_summary;
        $this->assertNotNull($ringkasan);
        $this->assertSame(1, $ringkasan['subjects_withheld']);
        $this->assertSame(1, $ringkasan['subjects_weighed']);
        $this->assertSame($set->id, $ringkasan['rule_sets'][0]['id']);
    }

    // --------------------------------------------------------- penjagaan CRM

    public function test_dorongan_crm_tidak_memuat_baris_yang_ditahan(): void
    {
        $this->setPenahan();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $run = ExtractRun::create([
            'org_id' => $this->org->id,
            'initiated_by_user_id' => $this->admin->id,
            'source' => 'consent_logs',
            'filters' => [],
            'output_target' => CrmCredential::PROVIDER_WEBHOOK,
            'output_target_ref' => 'https://penerima.contoh.id/hook',
            'record_count' => 2,
            'status' => ExtractRun::STATUS_PENDING,
        ]);

        (new PushExtractToCrmJob($run->id))->handle();

        Http::assertSent(function ($request) {
            $isi = $request->body();

            return str_contains($isi, 'bob@contoh.id') && ! str_contains($isi, 'alice@contoh.id');
        });
    }

    /**
     * Job ini bisa berjalan lama setelah tombolnya ditekan. Yang menentukan
     * adalah keadaan pada saat data benar-benar dikirim, bukan saat diminta.
     */
    public function test_penarikan_consent_setelah_run_dibuat_tetap_menahan(): void
    {
        $this->setPengizin();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $run = ExtractRun::create([
            'org_id' => $this->org->id,
            'initiated_by_user_id' => $this->admin->id,
            'source' => 'consent_logs',
            'filters' => [],
            'output_target' => CrmCredential::PROVIDER_WEBHOOK,
            'output_target_ref' => 'https://penerima.contoh.id/hook',
            'record_count' => 2,
            'status' => ExtractRun::STATUS_PENDING,
        ]);

        // Alice menarik persetujuannya SETELAH jalan ekstrak dibuat.
        $this->log($this->cpDijaga, 'alice@contoh.id', [$this->item->id => false], 0);

        (new PushExtractToCrmJob($run->id))->handle();

        // Kehadiran bob ditegaskan juga: tanpa itu, uji ini akan lulus bahkan
        // seandainya tidak ada apa pun yang terkirim.
        Http::assertSent(fn ($request) => str_contains($request->body(), 'bob@contoh.id')
            && ! str_contains($request->body(), 'alice@contoh.id'));
    }

    /**
     * CSV dan dorongan CRM harus memilih orang yang SAMA untuk penapis yang
     * sama. Dulu keduanya menyusun kuerinya sendiri-sendiri; uji ini yang
     * menahan keduanya tetap seiring.
     */
    public function test_csv_dan_dorongan_crm_memilih_orang_yang_sama(): void
    {
        $this->setPenahan();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        Sanctum::actingAs($this->admin);

        $csv = $this->unduhCsv();

        $run = ExtractRun::create([
            'org_id' => $this->org->id,
            'initiated_by_user_id' => $this->admin->id,
            'source' => 'consent_logs',
            'filters' => [],
            'output_target' => CrmCredential::PROVIDER_WEBHOOK,
            'output_target_ref' => 'https://penerima.contoh.id/hook',
            'record_count' => 2,
            'status' => ExtractRun::STATUS_PENDING,
        ]);
        (new PushExtractToCrmJob($run->id))->handle();

        $terkirim = collect(Http::recorded())
            ->map(fn ($pasangan) => (string) $pasangan[0]->body())
            ->implode("\n");

        $this->assertNotSame('', $terkirim, 'harus ada yang terkirim, kalau tidak perbandingannya hampa');

        foreach (['alice@contoh.id', 'bob@contoh.id'] as $surel) {
            $this->assertSame(
                str_contains($csv, $surel),
                str_contains($terkirim, $surel),
                "perlakuan atas {$surel} berbeda antara CSV dan CRM"
            );
        }
    }

    // ------------------------------------------------------------- pratinjau

    public function test_pratinjau_melaporkan_jumlah_yang_ditahan(): void
    {
        $this->setPenahan();
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/consent-extract/preview', [])->assertOk();

        $this->assertSame(2, $res->json('data.count'));
        $this->assertSame(1, $res->json('data.gate.subjects_withheld'));

        $contoh = collect($res->json('data.sample'))->firstWhere('email', 'alice@contoh.id');
        $this->assertTrue($contoh['withheld_by_rules']);
    }

    public function test_pratinjau_tanpa_penjagaan_tidak_melaporkan_gerbang(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/consent-extract/preview', [])->assertOk();

        $this->assertNull($res->json('data.gate'));
    }

    // --------------------------------------------------------------- segmen

    public function test_pengecualian_segmen_hanya_berlaku_bila_ekstraknya_menyatakan_segmen(): void
    {
        $this->setPengecualiSegmen('marketing_konvensional');
        Sanctum::actingAs($this->admin);

        // Tanpa menyebut segmen, yang berlaku hanya larangan penuh — dan
        // pengecualian bukan larangan penuh.
        $this->assertStringContainsString('alice@contoh.id', $this->unduhCsv());

        // Begitu ekstraknya menyatakan segmen yang dikecualikan, alice ditahan.
        $this->assertStringNotContainsString(
            'alice@contoh.id',
            $this->unduhCsv(['segment' => 'marketing_konvensional'])
        );

        // Segmen lain tidak terpengaruh.
        $this->assertStringContainsString(
            'alice@contoh.id',
            $this->unduhCsv(['segment' => 'marketing_syariah'])
        );
    }

    // ---------------------------------------------------------------- jejak

    public function test_jejak_ditulis_untuk_yang_ditahan_saja(): void
    {
        $this->setPenahan();
        Sanctum::actingAs($this->admin);

        $this->unduhCsv();

        $jejak = ConsentRuleDecision::withoutGlobalScope('org')->get();
        $this->assertCount(1, $jejak);
        $this->assertSame('alice@contoh.id', $jejak->first()->subject_identifier);
        $this->assertTrue($jejak->first()->blocked);
        $this->assertStringStartsWith('extract:', (string) $jejak->first()->context);
    }

    // -------------------------------------------------------- penapis titik

    /**
     * `consent_logs.collection_id` menyimpan UUID, sedangkan kotak isian di
     * wizard bertuliskan contoh "CNT-…". Sebelum ini, menyaring per titik
     * dengan kode selalu menghasilkan nol baris dan tampak seperti "memang
     * tidak ada datanya".
     */
    public function test_penapis_titik_menerima_kode_maupun_uuid(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([$this->cpBebas->collection_id, $this->cpBebas->id] as $nilai) {
            $res = $this->postJson('/api/consent-extract/preview', ['collection_id' => $nilai])->assertOk();
            $this->assertSame(1, $res->json('data.count'), "penapis dengan nilai {$nilai} harus menemukan satu baris");
            $this->assertSame('bob@contoh.id', $res->json('data.sample.0.email'));
        }
    }

    public function test_titik_yang_tidak_dikenali_menghasilkan_nol_bukan_semua(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/consent-extract/preview', ['collection_id' => 'CNT-SALAH-KETIK'])->assertOk();

        // Mengabaikan penapis yang salah ketik akan mengirim SEMUA orang.
        $this->assertSame(0, $res->json('data.count'));
    }

    // ---------------------------------------------------------------- bantu

    private function titik(string $kode, string $nama): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => $kode,
            'name' => $nama,
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);
    }

    /**
     * @param  array<mixed>  $pilihan
     */
    private function log(ConsentCollectionPoint $cp, string $surel, array $pilihan, int $menitLalu = 30): ConsentLog
    {
        $log = new ConsentLog([
            'org_id' => $this->org->id,
            'collection_id' => $cp->id,
            'user_identifier' => $surel,
            'email' => $surel,
            'consented_items' => $pilihan,
            'purpose_keys' => array_keys(array_filter($pilihan)),
        ]);
        $log->created_at = now()->subMinutes($menitLalu);
        $log->save();

        return $log;
    }

    /**
     * @param  array<string,mixed>  $penapis
     */
    private function unduhCsv(array $penapis = []): string
    {
        $res = $this->postJson('/api/consent-extract/run', array_merge($penapis, ['output_target' => 'csv']));
        $res->assertOk();

        return $res->streamedContent();
    }

    /** Bawaan "tahan" tanpa satu pun aturan "izinkan" = tidak pernah mengirim. */
    private function setPenahan(): ConsentRuleSet
    {
        return $this->pasang(ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Tahan semua',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]));
    }

    private function setPengizin(): ConsentRuleSet
    {
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Izinkan yang setuju',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        $this->aturan($set, ConsentRule::ACTION_INCLUDE, 'marketing_syariah', ConsentStateResolver::GRANTED);

        return $this->pasang($set);
    }

    private function setPengecualiSegmen(string $segmen): ConsentRuleSet
    {
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Kecualikan konvensional',
            'default_action' => ConsentRuleSet::ACTION_SEND,
        ]);

        $this->aturan($set, ConsentRule::ACTION_EXCLUDE, $segmen, ConsentStateResolver::GRANTED);

        return $this->pasang($set);
    }

    private function aturan(ConsentRuleSet $set, string $tindakan, string $segmen, string $keadaan): void
    {
        $aturan = ConsentRule::create([
            'org_id' => $this->org->id,
            'rule_set_id' => $set->id,
            'sequence' => 0,
            'name' => 'Aturan uji',
            'action' => $tindakan,
            'segment' => $segmen,
            'is_active' => true,
        ]);

        ConsentRuleCondition::create([
            'org_id' => $this->org->id,
            'rule_id' => $aturan->id,
            'collection_point_id' => $this->cpDijaga->id,
            'consent_item_id' => $this->item->id,
            'state' => $keadaan,
        ]);
    }

    private function pasang(ConsentRuleSet $set): ConsentRuleSet
    {
        $this->cpDijaga->forceFill(['consent_rule_set_id' => $set->id])->save();
        $this->cpDijaga->refresh();

        return $set;
    }
}
