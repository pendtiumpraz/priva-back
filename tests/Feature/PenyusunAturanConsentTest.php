<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentRule;
use App\Models\ConsentRuleCondition;
use App\Models\ConsentRuleDecision;
use App\Models\ConsentRuleSet;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consent\ConsentStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pintu API bagi penyusun aturan consent.
 *
 * Yang diuji di sini terutama dua hal yang tidak terlihat di layar: pasangan
 * (titik pengumpulan, item) tidak boleh menyeberang tenant atau saling tak
 * sepadan, dan penyimpanan daftar aturan harus utuh — tidak pernah separuh.
 */
class PenyusunAturanConsentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $orgLain;

    private User $admin;

    private ConsentCollectionPoint $cpA;

    private ConsentCollectionPoint $cpB;

    private ConsentItem $itemA;

    private ConsentItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Org A', 'slug' => 'org-a-'.Str::random(6)]);
        $this->orgLain = Organization::create(['name' => 'Org B', 'slug' => 'org-b-'.Str::random(6)]);

        $this->admin = User::factory()->create(['org_id' => $this->org->id, 'role' => 'admin']);

        $this->cpA = $this->collectionPoint($this->org, 'CNT-2026-001', 'Formulir Syariah');
        $this->cpB = $this->collectionPoint($this->org, 'CNT-2026-002', 'Formulir Umum');
        $this->itemA = $this->item($this->cpA, 'Penawaran syariah');
        $this->itemB = $this->item($this->cpB, 'Penawaran umum');
    }

    // ------------------------------------------------------------------ set

    public function test_membuat_dan_membaca_set_aturan(): void
    {
        Sanctum::actingAs($this->admin);

        $buat = $this->postJson('/api/consent-rule-sets', [
            'name' => 'Pemisahan syariah',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        $buat->assertCreated()->assertJsonPath('data.default_action', 'block');

        $this->getJson('/api/consent-rule-sets')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Pemisahan syariah')
            ->assertJsonPath('data.0.rules_count', 0);
    }

    public function test_set_milik_organisasi_lain_tidak_terlihat(): void
    {
        $asing = ConsentRuleSet::create([
            'org_id' => $this->orgLain->id,
            'name' => 'Punya tetangga',
            'default_action' => ConsentRuleSet::ACTION_SEND,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/consent-rule-sets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/consent-rule-sets/{$asing->id}")->assertNotFound();
        $this->putJson("/api/consent-rule-sets/{$asing->id}/rules", ['rules' => []])->assertNotFound();
    }

    // ---------------------------------------------------------------- aturan

    public function test_urutan_diambil_dari_posisi_larik_bukan_dari_kiriman_klien(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        // `sequence` sengaja dikirim terbalik. Yang harus berlaku adalah urutan
        // yang dilihat penyusun di layar, yaitu posisi di larik.
        $res = $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [
                $this->aturan('Pertama', ConsentRule::ACTION_EXCLUDE, 'konvensional', ['sequence' => 99]),
                $this->aturan('Kedua', ConsentRule::ACTION_INCLUDE, 'syariah', ['sequence' => 1]),
            ],
        ]);

        $res->assertOk();

        $tersimpan = ConsentRule::withoutGlobalScope('org')
            ->where('rule_set_id', $set->id)->orderBy('sequence')->get();

        $this->assertSame(['Pertama', 'Kedua'], $tersimpan->pluck('name')->all());
        $this->assertSame([0, 1], $tersimpan->pluck('sequence')->all());
    }

    public function test_menyimpan_daftar_mengganti_seluruh_aturan_lama(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Lama', ConsentRule::ACTION_EXCLUDE, 'konvensional')],
        ])->assertOk();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Baru', ConsentRule::ACTION_INCLUDE, 'syariah')],
        ])->assertOk();

        $sisa = ConsentRule::withoutGlobalScope('org')->where('rule_set_id', $set->id)->get();
        $this->assertCount(1, $sisa);
        $this->assertSame('Baru', $sisa->first()->name);

        // Kondisi milik aturan lama tidak boleh tertinggal sebagai yatim.
        $this->assertSame(
            1,
            ConsentRuleCondition::withoutGlobalScope('org')->where('org_id', $this->org->id)->count()
        );
    }

    public function test_kiriman_yang_ditolak_tidak_merusak_aturan_yang_sudah_ada(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Aturan sah', ConsentRule::ACTION_EXCLUDE, 'konvensional')],
        ])->assertOk();

        // Aturan kedua tidak sah: itemnya milik titik pengumpulan lain.
        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [
                $this->aturan('Masih sah', ConsentRule::ACTION_INCLUDE, 'syariah'),
                $this->aturan('Tidak sah', ConsentRule::ACTION_INCLUDE, 'apa pun', [
                    'conditions' => [[
                        'collection_point_id' => $this->cpA->id,
                        'consent_item_id' => $this->itemB->id,
                        'state' => ConsentStateResolver::GRANTED,
                    ]],
                ]),
            ],
        ])->assertStatus(422);

        $sisa = ConsentRule::withoutGlobalScope('org')->where('rule_set_id', $set->id)->get();
        $this->assertCount(1, $sisa, 'penolakan harus terjadi sebelum penghapusan aturan lama');
        $this->assertSame('Aturan sah', $sisa->first()->name);
    }

    // ------------------------------------------------------------- validasi

    public function test_item_harus_milik_titik_pengumpulan_yang_dipilih(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Silang', ConsentRule::ACTION_INCLUDE, 'segmen', [
                'conditions' => [[
                    'collection_point_id' => $this->cpA->id,
                    'consent_item_id' => $this->itemB->id,
                    'state' => ConsentStateResolver::GRANTED,
                ]],
            ])],
        ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.conditions.0.consent_item_id']);
    }

    public function test_titik_pengumpulan_organisasi_lain_ditolak(): void
    {
        $cpAsing = $this->collectionPoint($this->orgLain, 'CNT-2026-900', 'Punya tetangga');
        $itemAsing = $this->item($cpAsing, 'Item tetangga');

        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Menyeberang', ConsentRule::ACTION_INCLUDE, 'segmen', [
                'conditions' => [[
                    'collection_point_id' => $cpAsing->id,
                    'consent_item_id' => $itemAsing->id,
                    'state' => ConsentStateResolver::GRANTED,
                ]],
            ])],
        ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.conditions.0.collection_point_id']);
    }

    public function test_tindakan_segmen_wajib_punya_segmen_tapi_block_tidak(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Tanpa segmen', ConsentRule::ACTION_EXCLUDE, null)],
        ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.segment']);

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Tahan', ConsentRule::ACTION_BLOCK, null)],
        ])->assertOk();

        $this->assertNull(ConsentRule::withoutGlobalScope('org')->where('rule_set_id', $set->id)->first()->segment);
    }

    public function test_aturan_tanpa_kondisi_ditolak(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet();

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [[
                'name' => 'Kosong',
                'action' => ConsentRule::ACTION_INCLUDE,
                'segment' => 'segmen',
                'conditions' => [],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.conditions']);
    }

    // ------------------------------------------------------------- pratinjau

    public function test_pratinjau_memakai_mesin_yang_sama_dan_tidak_menulis_jejak(): void
    {
        Sanctum::actingAs($this->admin);
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", [
            'rules' => [$this->aturan('Syariah menutup konvensional', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional')],
        ])->assertOk();

        $log = new ConsentLog([
            'org_id' => $this->org->id,
            'collection_id' => $this->cpA->id,
            'user_identifier' => 'nasabah@contoh.id',
            'email' => 'nasabah@contoh.id',
            'consented_items' => [$this->itemA->id => true],
        ]);
        $log->save();

        $this->postJson("/api/consent-rule-sets/{$set->id}/preview", [
            'subject_identifier' => 'nasabah@contoh.id',
        ])
            ->assertOk()
            ->assertJsonPath('data.blocked', false)
            ->assertJsonPath('data.excluded.0', 'marketing_konvensional')
            ->assertJsonPath('data.matched.0.applied', true);

        $this->assertSame(0, ConsentRuleDecision::withoutGlobalScope('org')->count());
    }

    // --------------------------------------------------------------- pilihan

    public function test_pilihan_hanya_memuat_titik_dan_item_milik_tenant(): void
    {
        $cpAsing = $this->collectionPoint($this->orgLain, 'CNT-2026-900', 'Punya tetangga');
        $this->item($cpAsing, 'Item tetangga');

        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/consent-rule-options')->assertOk();

        $res->assertJsonCount(2, 'data');
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($cpAsing->id, $ids);

        $syariah = collect($res->json('data'))->firstWhere('id', $this->cpA->id);
        $this->assertSame([$this->itemA->id], collect($syariah['items'])->pluck('id')->all());
    }

    public function test_peran_tanpa_izin_tulis_tidak_boleh_menyimpan_aturan(): void
    {
        $biasa = User::factory()->create(['org_id' => $this->org->id, 'role' => 'staff']);
        $set = ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Set',
            'default_action' => ConsentRuleSet::ACTION_BLOCK,
        ]);

        Sanctum::actingAs($biasa);

        $this->putJson("/api/consent-rule-sets/{$set->id}/rules", ['rules' => []])->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function collectionPoint(Organization $org, string $kode, string $nama): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => $org->id,
            'collection_id' => $kode,
            'name' => $nama,
            'kind' => ConsentCollectionPoint::KIND_APP,
        ]);
    }

    private function item(ConsentCollectionPoint $cp, string $judul): ConsentItem
    {
        return ConsentItem::create([
            'collection_point_id' => $cp->id,
            'title' => $judul,
            'category' => 'marketing',
            'version' => '1.0',
            'is_active' => true,
        ]);
    }

    private function ruleSet(string $bawaan = ConsentRuleSet::ACTION_BLOCK): ConsentRuleSet
    {
        return ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Set Uji',
            'default_action' => $bawaan,
        ]);
    }

    /**
     * @param  array<string,mixed>  $tambahan
     * @return array<string,mixed>
     */
    private function aturan(string $nama, string $tindakan, ?string $segmen, array $tambahan = []): array
    {
        return array_merge([
            'name' => $nama,
            'action' => $tindakan,
            'segment' => $segmen,
            'conditions' => [[
                'collection_point_id' => $this->cpA->id,
                'consent_item_id' => $this->itemA->id,
                'state' => ConsentStateResolver::GRANTED,
            ]],
        ], $tambahan);
    }
}
