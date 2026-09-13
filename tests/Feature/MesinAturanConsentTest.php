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
use App\Services\Consent\ConsentRuleEvaluator;
use App\Services\Consent\ConsentStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mesin aturan consent lintas collection point.
 *
 * Kasus acuannya yang sebenarnya: subjek yang menyetujui marketing SYARIAH
 * tidak boleh ditawari marketing KONVENSIONAL — sementara keduanya ditangkap
 * di titik pengumpulan yang berbeda.
 */
class MesinAturanConsentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ConsentCollectionPoint $cpSyariah;

    private ConsentCollectionPoint $cpUmum;

    private ConsentItem $itemSyariah;

    private ConsentItem $itemUmum;

    private ConsentRuleEvaluator $mesin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-uji-'.Str::random(6)]);

        $this->cpSyariah = $this->collectionPoint('CNT-2026-001', 'Formulir Syariah');
        $this->cpUmum = $this->collectionPoint('CNT-2026-002', 'Formulir Umum');

        $this->itemSyariah = $this->item($this->cpSyariah, 'Penawaran produk syariah');
        $this->itemUmum = $this->item($this->cpUmum, 'Penawaran produk umum');

        $this->mesin = app(ConsentRuleEvaluator::class);
    }

    // ---------------------------------------------------------------- kasus inti

    public function test_setuju_syariah_membuat_segmen_konvensional_dikecualikan(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);

        $aturan = $this->rule($set, 1, 'Syariah menutup konvensional', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertFalse($keputusan->blocked);
        $this->assertSame(['marketing_konvensional'], $keputusan->excluded);
        $this->assertFalse($keputusan->allowsSegment('marketing_konvensional'));
    }

    public function test_tidak_setuju_syariah_tidak_menyalakan_aturan(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);

        $aturan = $this->rule($set, 1, 'Syariah menutup konvensional', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => false]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertSame([], $keputusan->excluded);
        $this->assertTrue($keputusan->allowsSegment('marketing_konvensional'));
    }

    public function test_kondisi_lintas_tiga_collection_point_di_satu_aturan(): void
    {
        $cpKetiga = $this->collectionPoint('CNT-2026-003', 'Formulir Ketiga');
        $itemKetiga = $this->item($cpKetiga, 'Penawaran mitra');

        $set = $this->ruleSet(ConsentRuleSet::ACTION_BLOCK);
        $aturan = $this->rule($set, 1, 'Tiga syarat sekaligus', ConsentRule::ACTION_INCLUDE, 'kampanye_gabungan');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);
        $this->condition($aturan, $this->cpUmum, $this->itemUmum, ConsentStateResolver::GRANTED);
        $this->condition($aturan, $cpKetiga, $itemKetiga, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);
        $this->log($this->cpUmum, 'nasabah@contoh.id', [$this->itemUmum->id => true]);

        // Dua dari tiga: belum cukup, karena kondisi di-AND.
        $this->assertTrue($this->mesin->evaluate($set, 'nasabah@contoh.id')->blocked);

        $this->log($cpKetiga, 'nasabah@contoh.id', [$itemKetiga->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');
        $this->assertFalse($keputusan->blocked);
        $this->assertSame(['kampanye_gabungan'], $keputusan->allowed);
    }

    // ------------------------------------------------- tiga keadaan, bukan dua

    public function test_belum_pernah_ditanya_berbeda_dari_menolak(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_BLOCK);
        $aturan = $this->rule($set, 1, 'Tawarkan ke yang belum pernah ditanya', ConsentRule::ACTION_INCLUDE, 'penawaran_awal');
        $this->condition($aturan, $this->cpUmum, $this->itemUmum, ConsentStateResolver::NEVER);

        // Belum ada log sama sekali → `never` → aturan menyala.
        $this->assertFalse($this->mesin->evaluate($set, 'baru@contoh.id')->blocked);

        // Sudah ditanya dan menolak → `not_granted`, BUKAN `never` → tidak menyala.
        $this->log($this->cpUmum, 'menolak@contoh.id', [$this->itemUmum->id => false]);
        $this->assertTrue($this->mesin->evaluate($set, 'menolak@contoh.id')->blocked);
    }

    public function test_penarikan_consent_membalik_keadaan(): void
    {
        $resolver = app(ConsentStateResolver::class);
        $pasangan = [[$this->cpSyariah->id, $this->itemSyariah->id]];

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true], 60);
        $kunci = $this->cpSyariah->id.'|'.$this->itemSyariah->id;

        $this->assertSame(
            ConsentStateResolver::GRANTED,
            $resolver->resolve($this->org->id, 'nasabah@contoh.id', $pasangan)[$kunci]
        );

        // Penangkapan berikutnya dengan nilai false = penarikan.
        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => false], 10);

        $this->assertSame(
            ConsentStateResolver::NOT_GRANTED,
            $resolver->resolve($this->org->id, 'nasabah@contoh.id', $pasangan)[$kunci]
        );
    }

    /**
     * Jebakan yang paling mudah lolos dari tinjauan: baris terbaru diperlakukan
     * sebagai keadaan LENGKAP. Kalau begitu, persetujuan di formulir syariah
     * akan terhapus hanya karena subjek kemudian mengisi formulir umum yang
     * tidak pernah menanyakannya.
     */
    public function test_log_yang_tidak_menyebut_item_tidak_menghapus_persetujuan(): void
    {
        $resolver = app(ConsentStateResolver::class);

        // Dua item di SATU collection point. Kalau ujinya memakai dua
        // collection point yang berbeda, ia akan hijau bahkan pada penerapan
        // yang naif — karena kuncinya sudah terpisah per titik pengumpulan.
        // Tabrakan yang sesungguhnya hanya mungkin terjadi di dalam satu titik.
        $kedua = $this->item($this->cpSyariah, 'Buletin mingguan');

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [
            $this->itemSyariah->id => true,
            $kedua->id => true,
        ], 60);

        // Penangkapan berikutnya hanya menanyakan buletin, dan subjek menolak.
        // Persetujuan produk syariah tidak pernah disinggung di sini.
        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$kedua->id => false], 10);

        $keadaan = $resolver->resolve($this->org->id, 'nasabah@contoh.id', [
            [$this->cpSyariah->id, $this->itemSyariah->id],
            [$this->cpSyariah->id, $kedua->id],
        ]);

        $this->assertSame(
            ConsentStateResolver::GRANTED,
            $keadaan[$this->cpSyariah->id.'|'.$this->itemSyariah->id],
            'item yang tidak disebut baris terbaru harus mempertahankan keadaan lamanya'
        );
        $this->assertSame(ConsentStateResolver::NOT_GRANTED, $keadaan[$this->cpSyariah->id.'|'.$kedua->id]);
    }

    public function test_bentuk_lama_daftar_id_dibaca_sebagai_disetujui(): void
    {
        $resolver = app(ConsentStateResolver::class);

        // Importer lama menulis daftar id yang disetujui, bukan peta id→bool.
        $this->log($this->cpSyariah, 'lama@contoh.id', [$this->itemSyariah->id]);

        $keadaan = $resolver->resolve($this->org->id, 'lama@contoh.id', [[$this->cpSyariah->id, $this->itemSyariah->id]]);

        $this->assertSame(ConsentStateResolver::GRANTED, $keadaan[$this->cpSyariah->id.'|'.$this->itemSyariah->id]);
    }

    // ------------------------------------------------------ urutan & presedensi

    public function test_aturan_pertama_menang_untuk_segmen_yang_sama(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);

        $pertama = $this->rule($set, 1, 'Larang lebih dulu', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional');
        $this->condition($pertama, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $kedua = $this->rule($set, 2, 'Izinkan belakangan', ConsentRule::ACTION_INCLUDE, 'marketing_konvensional');
        $this->condition($kedua, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertSame(['marketing_konvensional'], $keputusan->excluded);
        $this->assertSame([], $keputusan->allowed);

        // Aturan kedua tetap tercatat cocok, tapi ditandai tidak berlaku —
        // supaya tenant melihat aturannya tertutup, bukan hilang diam-diam.
        $this->assertCount(2, $keputusan->matched);
        $this->assertTrue($keputusan->matched[0]['applied']);
        $this->assertFalse($keputusan->matched[1]['applied']);
    }

    public function test_block_bersifat_terminal(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);

        $tahan = $this->rule($set, 1, 'Tahan total', ConsentRule::ACTION_BLOCK, null);
        $this->condition($tahan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $izin = $this->rule($set, 2, 'Izinkan apa pun', ConsentRule::ACTION_INCLUDE, 'marketing_konvensional');
        $this->condition($izin, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertTrue($keputusan->blocked);
        $this->assertSame([], $keputusan->allowed);
        $this->assertCount(1, $keputusan->matched, 'evaluasi harus berhenti di aturan block');
    }

    /**
     * Keputusan paling halus di mesin ini: pengecualian MEMPERSEMPIT, ia tidak
     * MENGIZINKAN. Tanpa pemisahan itu, subjek yang cocok dengan sebuah
     * larangan justru akan lebih mudah dikirim daripada yang tidak cocok apa pun.
     */
    public function test_pengecualian_saja_tidak_mengizinkan_pengiriman(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_BLOCK);

        $aturan = $this->rule($set, 1, 'Hanya melarang', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertTrue($keputusan->blocked, 'aturan larangan menyala, tapi belum ada yang menyatakan kapan boleh');
        $this->assertNotEmpty($keputusan->matched);
    }

    // ------------------------------------------------------------ tindakan bawaan

    public function test_tanpa_aturan_cocok_tindakan_bawaan_yang_menentukan(): void
    {
        $tahan = $this->ruleSet(ConsentRuleSet::ACTION_BLOCK);
        $aturan = $this->rule($tahan, 1, 'Butuh persetujuan', ConsentRule::ACTION_INCLUDE, 'apa_saja');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $keputusan = $this->mesin->evaluate($tahan, 'asing@contoh.id');
        $this->assertTrue($keputusan->blocked);
        $this->assertSame([], $keputusan->matched);

        $kirim = $this->ruleSet(ConsentRuleSet::ACTION_SEND);
        $this->assertFalse($this->mesin->evaluate($kirim, 'asing@contoh.id')->blocked);
    }

    public function test_aturan_nonaktif_dan_aturan_tanpa_kondisi_tidak_pernah_menyala(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_BLOCK);

        $nonaktif = $this->rule($set, 1, 'Nonaktif', ConsentRule::ACTION_INCLUDE, 'segmen_a');
        $this->condition($nonaktif, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);
        $nonaktif->update(['is_active' => false]);

        // Aturan tanpa kondisi BUKAN penampung terakhir — itu tugas default_action.
        $this->rule($set, 2, 'Tanpa kondisi', ConsentRule::ACTION_INCLUDE, 'segmen_b');

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $keputusan = $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertTrue($keputusan->blocked);
        $this->assertSame([], $keputusan->matched);
    }

    // ----------------------------------------------------------------- isolasi

    public function test_log_milik_organisasi_lain_tidak_ikut_terbaca(): void
    {
        $lain = Organization::create(['name' => 'Bank Lain', 'slug' => 'bank-lain-'.Str::random(6)]);

        // Barisnya sengaja DISILANGKAN: org_id milik tetangga, tapi
        // collection_id menunjuk titik pengumpulan kita. Inilah satu-satunya
        // bentuk data yang benar-benar diuji oleh saringan org_id — kalau
        // ujinya memakai collection point milik tetangga juga, saringan
        // `whereIn(collection_id)` sudah menutupnya lebih dulu dan uji ini
        // akan hijau meski saringan org_id dihapus.
        $log = new ConsentLog([
            'org_id' => $lain->id,
            'collection_id' => $this->cpSyariah->id,
            'user_identifier' => 'nasabah@contoh.id',
            'email' => 'nasabah@contoh.id',
            'consented_items' => [$this->itemSyariah->id => true],
        ]);
        $log->save();

        $keadaan = app(ConsentStateResolver::class)->resolve($this->org->id, 'nasabah@contoh.id', [
            [$this->cpSyariah->id, $this->itemSyariah->id],
        ]);

        $this->assertSame(
            ConsentStateResolver::NEVER,
            $keadaan[$this->cpSyariah->id.'|'.$this->itemSyariah->id]
        );
    }

    public function test_subjek_dicocokkan_tanpa_membedakan_huruf_besar_kecil(): void
    {
        $this->log($this->cpSyariah, 'Nasabah@Contoh.ID', [$this->itemSyariah->id => true]);

        $keadaan = app(ConsentStateResolver::class)->resolve($this->org->id, 'nasabah@contoh.id', [
            [$this->cpSyariah->id, $this->itemSyariah->id],
        ]);

        $this->assertSame(ConsentStateResolver::GRANTED, $keadaan[$this->cpSyariah->id.'|'.$this->itemSyariah->id]);
    }

    // -------------------------------------------------------------- jejak keputusan

    public function test_jejak_keputusan_menyimpan_aturan_dan_keadaan_saat_itu(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);
        $aturan = $this->rule($set, 1, 'Syariah menutup konvensional', ConsentRule::ACTION_EXCLUDE, 'marketing_konvensional');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->log($this->cpSyariah, 'nasabah@contoh.id', [$this->itemSyariah->id => true]);

        $this->mesin->evaluateAndRecord($set, 'nasabah@contoh.id');

        $jejak = ConsentRuleDecision::withoutGlobalScope('org')->first();
        $this->assertNotNull($jejak);
        $this->assertFalse($jejak->blocked);
        $this->assertSame(['marketing_konvensional'], $jejak->segments['excluded']);
        $this->assertSame($aturan->id, $jejak->matched[0]['rule_id']);
        $this->assertSame(
            ConsentStateResolver::GRANTED,
            $jejak->states[$this->cpSyariah->id.'|'.$this->itemSyariah->id]
        );
        $this->assertNotNull($jejak->decided_at);
    }

    public function test_pratinjau_tidak_menulis_jejak(): void
    {
        $set = $this->ruleSet(ConsentRuleSet::ACTION_SEND);
        $aturan = $this->rule($set, 1, 'Apa saja', ConsentRule::ACTION_EXCLUDE, 'segmen_a');
        $this->condition($aturan, $this->cpSyariah, $this->itemSyariah, ConsentStateResolver::GRANTED);

        $this->mesin->evaluate($set, 'nasabah@contoh.id');

        $this->assertSame(0, ConsentRuleDecision::withoutGlobalScope('org')->count());
    }

    // ------------------------------------------------------------------ pembantu

    private function collectionPoint(string $kode, string $nama): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
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
            'is_required' => false,
            'is_active' => true,
        ]);
    }

    private function ruleSet(string $bawaan): ConsentRuleSet
    {
        return ConsentRuleSet::create([
            'org_id' => $this->org->id,
            'name' => 'Set Uji',
            'default_action' => $bawaan,
            'is_active' => true,
        ]);
    }

    private function rule(ConsentRuleSet $set, int $urutan, string $nama, string $tindakan, ?string $segmen): ConsentRule
    {
        return ConsentRule::create([
            'org_id' => $this->org->id,
            'rule_set_id' => $set->id,
            'sequence' => $urutan,
            'name' => $nama,
            'action' => $tindakan,
            'segment' => $segmen,
            'is_active' => true,
        ]);
    }

    private function condition(ConsentRule $aturan, ConsentCollectionPoint $cp, ConsentItem $item, string $keadaan): ConsentRuleCondition
    {
        return ConsentRuleCondition::create([
            'org_id' => $this->org->id,
            'rule_id' => $aturan->id,
            'collection_point_id' => $cp->id,
            'consent_item_id' => $item->id,
            'state' => $keadaan,
        ]);
    }

    /**
     * `created_at` ditulis eksplisit: urutannya yang menentukan mana yang
     * terbaru, dan dua baris berdetik sama memang ambigu menurut rancangannya.
     *
     * @param  array<mixed>  $pilihan
     */
    private function log(ConsentCollectionPoint $cp, string $subjek, array $pilihan, int $menitLalu = 30): ConsentLog
    {
        $log = new ConsentLog([
            'org_id' => $this->org->id,
            'collection_id' => $cp->id,
            'user_identifier' => $subjek,
            'email' => strtolower($subjek),
            'consented_items' => $pilihan,
        ]);
        $log->created_at = now()->subMinutes($menitLalu);
        $log->save();

        return $log;
    }
}
