<?php

namespace Tests\Feature;

use App\Jobs\FireConsentWebhookJob;
use App\Jobs\PushConsentToCrmJob;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentRule;
use App\Models\ConsentRuleCondition;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consent\ConsentRuleEvaluator;
use App\Services\Consent\ConsentStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gerbang antara penangkapan consent dan pengiriman ke luar.
 *
 * Uji yang paling penting di berkas ini bukan yang memblokir, melainkan yang
 * TIDAK: titik pengumpulan tanpa set aturan harus berperilaku persis seperti
 * sebelum fitur ini ada. Fitur kepatuhan yang diam-diam mematikan webhook
 * tenant lain adalah kerusakan, bukan perlindungan.
 */
class GerbangKirimConsentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ConsentCollectionPoint $cp;

    private ConsentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Bank Uji',
            'slug' => 'bank-uji-'.Str::random(6),
            'settings' => ['crm_connections' => ['hubspot' => ['api_key' => 'x']]],
        ]);

        $this->cp = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-001',
            'name' => 'Formulir Syariah',
            'kind' => ConsentCollectionPoint::KIND_APP,
            'webhook_url' => 'https://penerima.contoh.id/hook',
        ]);

        $this->item = ConsentItem::create([
            'collection_point_id' => $this->cp->id,
            'title' => 'Penawaran syariah',
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------- kompatibilitas mundur

    public function test_titik_tanpa_set_aturan_mengirim_seperti_sebelumnya(): void
    {
        Queue::fake();

        $this->tangkap(['nasabah@contoh.id' => true]);

        Queue::assertPushed(FireConsentWebhookJob::class);
        Queue::assertPushed(PushConsentToCrmJob::class);
        $this->assertSame(0, ConsentRuleDecision::withoutGlobalScope('org')->count());
    }

    public function test_set_yang_dinonaktifkan_diperlakukan_seperti_tak_terpasang(): void
    {
        $set = $this->setYangMemblokir();
        $set->update(['is_active' => false]);

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        Queue::assertPushed(FireConsentWebhookJob::class);
        $this->assertSame(0, ConsentRuleDecision::withoutGlobalScope('org')->count());
    }

    // ------------------------------------------------------------ penjagaan

    public function test_keputusan_menahan_menghentikan_webhook_dan_dorongan_crm(): void
    {
        $this->setYangMemblokir();

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        Queue::assertNotPushed(FireConsentWebhookJob::class);
        // Satu gerbang untuk semua tujuan: menjaga webhook saja sementara CRM
        // tetap jalan lebih buruk daripada tidak menjaga sama sekali.
        Queue::assertNotPushed(PushConsentToCrmJob::class);
    }

    public function test_keputusan_mengizinkan_menyertakan_blok_decision_di_payload(): void
    {
        $this->setYangMengizinkan();

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        Queue::assertPushed(FireConsentWebhookJob::class, function (FireConsentWebhookJob $job) {
            $d = $job->payload['decision'] ?? null;

            return is_array($d)
                && $d['allowed_segments'] === ['marketing_syariah']
                && $d['excluded_segments'] === []
                // Riwayat consent subjek TIDAK ikut dikirim — penerima hanya
                // perlu tahu keputusannya, bukan seluruh jejak persetujuannya.
                && ! array_key_exists('states', $d);
        });
    }

    public function test_jejak_keputusan_mencatat_konteks_dan_titik_pengumpulan(): void
    {
        $set = $this->setYangMemblokir();

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        $jejak = ConsentRuleDecision::withoutGlobalScope('org')->first();
        $this->assertNotNull($jejak);
        $this->assertTrue($jejak->blocked);
        $this->assertSame('capture', $jejak->context);
        $this->assertSame($this->cp->id, $jejak->collection_point_id);
        $this->assertSame($set->id, $jejak->rule_set_id);
        $this->assertSame('nasabah@contoh.id', $jejak->subject_identifier);
    }

    /**
     * Arah kegagalan dipilih sadar: pelanggaran tidak bisa ditarik kembali,
     * webhook yang tertahan masih bisa dikirim ulang.
     */
    public function test_evaluasi_yang_galat_menahan_pengiriman_dan_tetap_tercatat(): void
    {
        $this->setYangMengizinkan();

        $this->app->bind(ConsentRuleEvaluator::class, function () {
            $palsu = \Mockery::mock(ConsentRuleEvaluator::class);
            $palsu->shouldReceive('evaluate')->andThrow(new \RuntimeException('mesin rusak'));

            return $palsu;
        });

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        Queue::assertNotPushed(FireConsentWebhookJob::class);

        $jejak = ConsentRuleDecision::withoutGlobalScope('org')->first();
        $this->assertNotNull($jejak, 'kegagalan harus tetap meninggalkan jejak, bukan menghilang diam-diam');
        $this->assertTrue($jejak->blocked);
    }

    public function test_penangkapan_consent_tetap_tersimpan_meski_pengiriman_ditahan(): void
    {
        $this->setYangMemblokir();

        Queue::fake();
        $this->tangkap(['nasabah@contoh.id' => true]);

        // Catatan persetujuan subjek adalah artefak hukumnya. Ia tidak boleh
        // ikut hilang hanya karena pengiriman ke luar ditahan.
        $this->assertSame(1, ConsentLog::withoutGlobalScope('org')->count());
    }

    // ----------------------------------------------------------- penyambungan

    public function test_menyambung_dan_melepas_titik_pengumpulan(): void
    {
        $admin = User::factory()->create(['org_id' => $this->org->id, 'role' => 'admin']);
        $kedua = ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-2026-002',
            'name' => 'Formulir Umum',
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);

        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id, 'name' => 'Set', 'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/consent-rule-sets/{$set->id}/collection-points", [
            'collection_point_ids' => [$this->cp->id, $kedua->id],
        ])->assertOk();

        $this->assertSame($set->id, $this->cp->fresh()->consent_rule_set_id);
        $this->assertSame($set->id, $kedua->fresh()->consent_rule_set_id);

        // Titik yang tidak lagi diminta harus dilepas, bukan dibiarkan menempel.
        $this->putJson("/api/consent-rule-sets/{$set->id}/collection-points", [
            'collection_point_ids' => [$kedua->id],
        ])->assertOk();

        $this->assertNull($this->cp->fresh()->consent_rule_set_id);
        $this->assertSame($set->id, $kedua->fresh()->consent_rule_set_id);
    }

    public function test_titik_organisasi_lain_tidak_bisa_disambungkan(): void
    {
        $lain = Organization::create(['name' => 'Lain', 'slug' => 'lain-'.Str::random(6)]);
        $cpAsing = ConsentCollectionPoint::create([
            'org_id' => $lain->id,
            'collection_id' => 'CNT-2026-900',
            'name' => 'Tetangga',
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);

        $admin = User::factory()->create(['org_id' => $this->org->id, 'role' => 'admin']);
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id, 'name' => 'Set', 'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/consent-rule-sets/{$set->id}/collection-points", [
            'collection_point_ids' => [$cpAsing->id],
        ])->assertStatus(422);

        $this->assertNull($cpAsing->fresh()->consent_rule_set_id);
    }

    /**
     * ModuleCrudController memperbarui collection point dengan
     * `update($request->all())`. Kalau `consent_rule_set_id` masuk $fillable,
     * payload biasa bisa menetapkan set milik organisasi lain sebagai penjaga —
     * atau melepas penjagaan sendiri tanpa melewati pemeriksaan apa pun.
     */
    public function test_penjagaan_tidak_bisa_ditetapkan_lewat_crud_umum(): void
    {
        $lain = Organization::create(['name' => 'Lain', 'slug' => 'lain-'.Str::random(6)]);
        $setAsing = ConsentRuleSet::create([
            'org_id' => $lain->id, 'name' => 'Set tetangga', 'default_action' => ConsentRuleSet::ACTION_SEND,
        ]);

        $admin = User::factory()->create(['org_id' => $this->org->id, 'role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/m/consent/{$this->cp->id}", [
            'name' => 'Nama baru',
            'consent_rule_set_id' => $setAsing->id,
        ])->assertSuccessful();

        $segar = $this->cp->fresh();

        // Nama HARUS berubah. Tanpa penegasan ini, uji akan hijau seandainya
        // requestnya ditolak karena sebab lain — dan tidak membuktikan apa pun
        // tentang $fillable.
        $this->assertSame('Nama baru', $segar->name, 'pembaruan biasa harus tetap berjalan');
        $this->assertNull(
            $segar->consent_rule_set_id,
            'consent_rule_set_id harus di luar $fillable supaya hanya endpoint tersendiri yang bisa menetapkannya'
        );
    }

    // --------------------------------------------------------------- retensi

    public function test_perintah_pemangkasan_membuang_jejak_yang_kedaluwarsa(): void
    {
        $buat = function (int $hariLalu) {
            $j = ConsentRuleDecision::create([
                'org_id' => $this->org->id,
                'rule_set_id' => (string) Str::uuid(),
                'subject_identifier' => 'x@contoh.id',
                'blocked' => false,
                'decided_at' => now()->subDays($hariLalu),
            ]);

            return $j;
        };

        $lama = $buat(400);
        $baru = $buat(10);

        $this->artisan('consent:prune-rule-decisions', ['--days' => 365])->assertSuccessful();

        $this->assertNull(ConsentRuleDecision::withoutGlobalScope('org')->find($lama->id));
        $this->assertNotNull(ConsentRuleDecision::withoutGlobalScope('org')->find($baru->id));
    }

    // ---------------------------------------------------------------- bantu

    /**
     * @param  array<string,bool>  $subjek  penanda subjek => disetujui?
     */
    private function tangkap(array $subjek): void
    {
        foreach ($subjek as $penanda => $setuju) {
            $this->postJson('/api/public/consent', [
                'collection_id' => $this->cp->collection_id,
                'user_identifier' => $penanda,
                'consented_items' => [$this->item->id => $setuju],
            ])->assertSuccessful();
        }
    }

    private function setYangMemblokir(): ConsentRuleSet
    {
        // Bawaan "tahan" tanpa satu pun aturan "izinkan" = tidak pernah mengirim.
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Tahan semua',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        $this->sambungkan($set);

        return $set;
    }

    private function setYangMengizinkan(): ConsentRuleSet
    {
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Izinkan syariah',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        $aturan = ConsentRule::create([
            'org_id' => $this->org->id,
            'rule_set_id' => $set->id,
            'sequence' => 0,
            'name' => 'Setuju syariah → boleh segmen syariah',
            'action' => ConsentRule::ACTION_INCLUDE,
            'segment' => 'marketing_syariah',
            'is_active' => true,
        ]);

        ConsentRuleCondition::create([
            'org_id' => $this->org->id,
            'rule_id' => $aturan->id,
            'collection_point_id' => $this->cp->id,
            'consent_item_id' => $this->item->id,
            'state' => ConsentStateResolver::GRANTED,
        ]);

        $this->sambungkan($set);

        return $set;
    }

    private function sambungkan(ConsentRuleSet $set): void
    {
        $this->cp->forceFill(['consent_rule_set_id' => $set->id])->save();
        $this->cp->refresh();
    }
}
