<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Support\Schema\DpiaDefaultSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DPIA — penilaian kebutuhan & proporsionalitas + timing + konsultasi Lembaga
 * (PP 33/2026 Pasal 121-122). Sebelumnya field ini tidak ada (PDF memalsukannya).
 */
class DpiaNecessityProportionalityTest extends TestCase
{
    use RefreshDatabase;

    private function fieldNames(): array
    {
        $section = collect(DpiaDefaultSchema::sections())->firstWhere('section_key', 'informasi_dpia');

        return collect($section['fields'])->pluck('field_name')->all();
    }

    #[Test]
    public function skema_dpia_punya_field_kebutuhan_proporsionalitas_timing_konsultasi(): void
    {
        $names = $this->fieldNames();

        foreach (['necessity_justification', 'proportionality_justification', 'assessment_timing', 'authority_consultation', 'authority_consultation_reason'] as $f) {
            $this->assertContains($f, $names, "Field {$f} harus ada di skema DPIA");
        }
    }

    #[Test]
    public function necessity_dan_proporsionalitas_wajib_di_skema(): void
    {
        $section = collect(DpiaDefaultSchema::sections())->firstWhere('section_key', 'informasi_dpia');
        $byName = collect($section['fields'])->keyBy('field_name');

        $this->assertTrue($byName['necessity_justification']['is_required']);
        $this->assertTrue($byName['proportionality_justification']['is_required']);
        $this->assertTrue($byName['assessment_timing']['is_required']);
    }

    #[Test]
    public function dpia_menyimpan_necessity_di_wizard_data(): void
    {
        $org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);

        $dpia = Dpia::create([
            'org_id' => $org->id,
            'registration_number' => 'DPIA-2026-'.substr(uniqid(), -3),
            'description' => 'Skoring kredit otomatis',
            'wizard_data' => [
                'informasi_dpia' => [
                    'necessity_justification' => 'Diperlukan untuk penilaian kelayakan kredit.',
                    'proportionality_justification' => 'Data terbatas pada yang relevan dengan penilaian.',
                    'assessment_timing' => 'Sebelum pemrosesan dimulai',
                    'authority_consultation' => 'Dipertimbangkan',
                ],
            ],
        ]);

        $fresh = $dpia->fresh();
        $this->assertSame('Diperlukan untuk penilaian kelayakan kredit.', $fresh->wizard_data['informasi_dpia']['necessity_justification']);
        $this->assertSame('Sebelum pemrosesan dimulai', $fresh->wizard_data['informasi_dpia']['assessment_timing']);
    }
}
