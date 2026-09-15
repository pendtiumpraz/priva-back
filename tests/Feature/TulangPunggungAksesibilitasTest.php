<?php

namespace Tests\Feature;

use App\Models\AccessibilityProvision;
use App\Models\CapacityAssessment;
use App\Models\DisabilityServiceScope;
use App\Models\DsrRequest;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tulang punggung aksesibilitas — PP 33/2026 Pasal 38 & 39, Fase 1b.
 *
 * Yang diuji adalah INVARIAN BENTUKNYA. Empat di antaranya kalau salah akan
 * menghasilkan fitur yang justru merugikan orang yang hendak dilindungi:
 *
 *   - mendaftarkan ORANG alih-alih kanal;
 *   - menganggap centang tanpa tanggal uji sebagai bukti;
 *   - memperlakukan semua ragam disabilitas sebagai "harus lewat wali";
 *   - mewajibkan wali di portal DSR.
 */
class TulangPunggungAksesibilitasTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    // ─────────────── Prasarana ───────────────

    private function prasarana(array $tambahan = []): AccessibilityProvision
    {
        return AccessibilityProvision::create(array_merge([
            'org_id' => $this->org->id,
            'channel' => 'Widget Consent',
            'format' => AccessibilityProvision::FORMAT_TTS,
            'is_available' => true,
        ], $tambahan));
    }

    #[Test]
    public function tersedia_tanpa_tanggal_uji_belum_terbukti(): void
    {
        // Prasarana yang tidak pernah diuji ulang adalah KLAIM, bukan fakta —
        // dan klaim itulah yang dibawa ke audit.
        $p = $this->prasarana(['last_tested_at' => null]);

        $this->assertTrue($p->is_available);
        $this->assertFalse($p->sudahTerbukti());
    }

    #[Test]
    public function tersedia_dan_pernah_diuji_baru_terbukti(): void
    {
        $this->assertTrue($this->prasarana(['last_tested_at' => now()->subMonth()])->sudahTerbukti());
    }

    #[Test]
    public function belum_pernah_diuji_bukan_berarti_kedaluwarsa(): void
    {
        // Dua keadaan yang berbeda dan tidak boleh dicampur: "pernah bekerja
        // lalu basi" vs "tidak pernah ada". Mencampurnya menyamarkan yang kedua.
        $p = $this->prasarana([
            'last_tested_at' => null,
            'next_review_at' => now()->subYear(),
        ]);

        $this->assertFalse($p->ujiKedaluwarsa());
        $this->assertFalse($p->sudahTerbukti());
    }

    #[Test]
    public function uji_yang_lewat_tanggal_tinjau_ditandai_kedaluwarsa(): void
    {
        $p = $this->prasarana([
            'last_tested_at' => now()->subYears(2),
            'next_review_at' => now()->subMonth(),
        ]);

        $this->assertTrue($p->ujiKedaluwarsa());
    }

    #[Test]
    public function satu_kanal_tidak_bisa_punya_dua_baris_format_sama(): void
    {
        $this->prasarana(['format' => AccessibilityProvision::FORMAT_BRAILLE]);

        $this->expectException(QueryException::class);
        $this->prasarana(['format' => AccessibilityProvision::FORMAT_BRAILLE]);
    }

    // ─────────────── Ragam dilayani ───────────────

    #[Test]
    public function ragam_mental_tidak_boleh_memberi_persetujuan_sendiri(): void
    {
        // Penjelasan Pasal 39 ayat (1): untuk disabilitas mental, pengendali
        // tidak boleh meminta persetujuan langsung kepada yang bersangkutan.
        $this->assertFalse(DisabilityServiceScope::bolehMandiri(DisabilityServiceScope::RAGAM_MENTAL));
    }

    #[Test]
    public function ragam_lain_tetap_boleh_memberi_persetujuan_sendiri(): void
    {
        // Ini sama pentingnya dengan uji di atas. Kapasitas hukum penyandang
        // disabilitas TIDAK otomatis hilang — memperlakukan semua ragam sebagai
        // "harus lewat wali" berarti mencabut hak orang yang memilikinya.
        foreach ([
            DisabilityServiceScope::RAGAM_FISIK,
            DisabilityServiceScope::RAGAM_INTELEKTUAL,
            DisabilityServiceScope::RAGAM_NETRA,
            DisabilityServiceScope::RAGAM_RUNGU,
            DisabilityServiceScope::RAGAM_WICARA,
            DisabilityServiceScope::RAGAM_GANDA,
        ] as $ragam) {
            $this->assertTrue(
                DisabilityServiceScope::bolehMandiri($ragam),
                "ragam {$ragam} seharusnya tetap boleh memberi persetujuan sendiri",
            );
        }
    }

    #[Test]
    public function ragam_mengikuti_kategori_uu_8_2016(): void
    {
        // Fisik, intelektual, mental, sensorik (UU 8/2016 Pasal 4). Sensorik
        // dipecah tiga karena prasarananya berbeda sama sekali.
        $this->assertContains(DisabilityServiceScope::RAGAM_FISIK, DisabilityServiceScope::RAGAM);
        $this->assertContains(DisabilityServiceScope::RAGAM_INTELEKTUAL, DisabilityServiceScope::RAGAM);
        $this->assertContains(DisabilityServiceScope::RAGAM_MENTAL, DisabilityServiceScope::RAGAM);
        $this->assertCount(7, DisabilityServiceScope::RAGAM);
    }

    #[Test]
    public function yang_didaftarkan_kanal_bukan_orang(): void
    {
        // Penjaga niat. Kalau suatu saat ada kolom yang menunjuk pengguna atau
        // subjek di tabel ini, keputusan "kanal bukan orang" sudah dilanggar.
        $kolom = \Schema::getColumnListing('disability_service_scopes');

        foreach (['user_id', 'subject_id', 'subject_identifier', 'person_id'] as $terlarang) {
            $this->assertNotContains($terlarang, $kolom);
        }
        $this->assertContains('channel', $kolom);
    }

    // ─────────────── Penilaian kapasitas ───────────────

    private function penilaian(string $hasil): CapacityAssessment
    {
        return CapacityAssessment::create([
            'org_id' => $this->org->id,
            'subject_class' => 'disabilitas',
            'result' => $hasil,
            'reason' => 'Subjek memahami penjelasan setelah dibacakan ulang.',
            'assessed_at' => now(),
        ]);
    }

    #[Test]
    public function mampu_dan_perlu_pendampingan_sama_sama_memutuskan_sendiri(): void
    {
        // TIGA jalur, bukan dua. Pendamping membantu memahami; subjeknya tetap
        // yang memutuskan. Menyederhanakannya jadi dua akan mendorong penilai
        // memilih "diwakili wali" untuk kasus yang cukup didampingi.
        $this->assertTrue($this->penilaian(CapacityAssessment::HASIL_MAMPU)->subjekMemutuskanSendiri());
        $this->assertTrue($this->penilaian(CapacityAssessment::HASIL_PENDAMPINGAN)->subjekMemutuskanSendiri());
        $this->assertFalse($this->penilaian(CapacityAssessment::HASIL_WALI)->subjekMemutuskanSendiri());
    }

    #[Test]
    public function alasan_wajib_diisi(): void
    {
        // Penilaian tanpa alasan tertulis tidak bisa ditinjau ulang, tidak bisa
        // dibantah subjeknya, dan tidak bisa dipertanggungjawabkan saat diaudit.
        $this->expectException(QueryException::class);

        CapacityAssessment::create([
            'org_id' => $this->org->id,
            'subject_class' => 'anak',
            'result' => CapacityAssessment::HASIL_MAMPU,
            'assessed_at' => now(),
        ]);
    }

    // ─────────────── Pemohon DSR ───────────────

    private function permohonan(array $tambahan = []): DsrRequest
    {
        return DsrRequest::create(array_merge([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -6),
            'request_type' => 'access',
            'requester_name' => 'Budi',
            'requester_email' => 'budi'.uniqid().'@contoh.id',
            'status' => 'pending_verification',
            'deadline_at' => now()->addHours(72),
        ], $tambahan));
    }

    #[Test]
    public function pemohon_bawaannya_subjek_sendiri(): void
    {
        // Pasal 39 ayat (5): "dan/atau" berarti subjek boleh mengajukan sendiri.
        // Kalau portal mewajibkan wali, kita melanggar pasal yang sedang kita
        // bantu penuhi.
        $p = $this->permohonan()->fresh();

        $this->assertSame(DsrRequest::PEMOHON_SUBJEK, $p->requester_type);
        $this->assertSame('dewasa', $p->subject_class);
        $this->assertFalse($p->butuhBuktiWali());
    }

    #[Test]
    public function hanya_jalur_wali_yang_butuh_bukti_kewenangan(): void
    {
        // Pendamping BUKAN pengambil keputusan — menuntut bukti darinya akan
        // menghambat orang yang sebenarnya mengajukan sendiri.
        $this->assertTrue($this->permohonan(['requester_type' => DsrRequest::PEMOHON_WALI])->butuhBuktiWali());
        $this->assertFalse($this->permohonan(['requester_type' => DsrRequest::PEMOHON_PENDAMPING])->butuhBuktiWali());
        $this->assertFalse($this->permohonan(['requester_type' => DsrRequest::PEMOHON_SUBJEK])->butuhBuktiWali());
    }

    #[Test]
    public function hubungan_pemohon_tersimpan_saat_lewat_wali(): void
    {
        $p = $this->permohonan([
            'requester_type' => DsrRequest::PEMOHON_WALI,
            'requester_relation' => 'orang_tua',
            'subject_class' => 'anak',
        ])->fresh();

        $this->assertSame('orang_tua', $p->requester_relation);
        $this->assertSame('anak', $p->subject_class);
    }
}
