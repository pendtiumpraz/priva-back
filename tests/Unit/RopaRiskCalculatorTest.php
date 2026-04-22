<?php

namespace Tests\Unit;

use App\Services\RopaRiskCalculator;
use PHPUnit\Framework\TestCase;

class RopaRiskCalculatorTest extends TestCase
{
    private RopaRiskCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new RopaRiskCalculator();
    }

    public function test_empty_wizard_returns_low(): void
    {
        $r = $this->calc->calculate([]);
        $this->assertSame('low', $r['level']);
        $this->assertEmpty($r['triggers']);
    }

    public function test_ai_full_decision_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['bantuan_ai' => 'Ya (Keputusan Sepenuhnya menggunakan AI)'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('ai_full_decision', $r['triggers']);
    }

    public function test_automated_decision_full_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['otomatis' => 'Ya, Keputusan Penuh'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('automated_decision_full', $r['triggers']);
    }

    public function test_profiling_marketing_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['pemrofilan' => ['Marketing', 'Personalisasi Konten']],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('profiling', $r['triggers']);
    }

    public function test_profiling_not_applicable_does_not_trigger(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['pemrofilan' => 'Not Applicable'],
        ]);
        $this->assertSame('low', $r['level']);
    }

    public function test_new_technology_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['teknologi_baru' => 'Ya'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('new_technology', $r['triggers']);
    }

    public function test_mass_subjects_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'pengumpulan_data' => ['jumlah_subjek' => '> 1.000 subjek'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('mass_subjects', $r['triggers']);
    }

    public function test_sensitive_data_kesehatan_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'pengumpulan_data' => ['jenis_data_spesifik' => ['Data dan Informasi Kesehatan']],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('sensitive_data', $r['triggers']);
    }

    public function test_cross_border_transfer_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'pengiriman_data' => ['transfer_luar' => 'Ya'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('cross_border_transfer', $r['triggers']);
    }

    public function test_prior_incident_triggers_high(): void
    {
        $r = $this->calc->calculate([
            'retensi_keamanan' => ['pernah_insiden' => 'Ya'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('prior_incident', $r['triggers']);
    }

    public function test_ai_partial_triggers_medium(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => ['bantuan_ai' => 'Ya (Keputusan Akhir dari Manusia)'],
        ]);
        $this->assertSame('medium', $r['level']);
        $this->assertContains('ai_partial', $r['triggers']);
    }

    public function test_third_party_processor_triggers_medium(): void
    {
        $r = $this->calc->calculate([
            'penggunaan_penyimpanan' => ['pihak_ketiga' => 'Ya'],
        ]);
        $this->assertSame('medium', $r['level']);
        $this->assertContains('third_party_processor', $r['triggers']);
    }

    public function test_multiple_high_triggers_all_captured(): void
    {
        $r = $this->calc->calculate([
            'informasi_pemrosesan' => [
                'bantuan_ai' => 'Ya (Keputusan Sepenuhnya menggunakan AI)',
                'teknologi_baru' => 'Ya',
            ],
            'pengumpulan_data' => [
                'jumlah_subjek' => '> 1.000 subjek',
                'jenis_data_spesifik' => ['Data Anak', 'Data Biometrik'],
            ],
            'pengiriman_data' => ['transfer_luar' => 'Ya'],
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertContains('ai_full_decision', $r['triggers']);
        $this->assertContains('new_technology', $r['triggers']);
        $this->assertContains('mass_subjects', $r['triggers']);
        $this->assertContains('sensitive_data', $r['triggers']);
        $this->assertContains('cross_border_transfer', $r['triggers']);
    }

    public function test_high_overrides_medium(): void
    {
        $r = $this->calc->calculate([
            'penggunaan_penyimpanan' => ['pihak_ketiga' => 'Ya'], // medium
            'pengiriman_data' => ['transfer_luar' => 'Ya'],       // high
        ]);
        $this->assertSame('high', $r['level']);
        $this->assertNotContains('third_party_processor', $r['triggers']);
    }
}
