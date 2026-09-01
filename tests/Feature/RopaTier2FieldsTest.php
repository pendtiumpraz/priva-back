<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Support\Schema\RopaDefaultSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RoPA — kelengkapan field PP 33/2026: kategori subjek (Pasal 74(2)g),
 * pemenuhan hak subjek (74(2)i), dan Pengendali Bersama (Pasal 63).
 */
class RopaTier2FieldsTest extends TestCase
{
    use RefreshDatabase;

    private function names(string $sectionKey): array
    {
        $s = collect(RopaDefaultSchema::sections())->firstWhere('section_key', $sectionKey);

        return collect($s['fields'])->pluck('field_name')->all();
    }

    #[Test]
    public function skema_ropa_punya_field_pp33(): void
    {
        $this->assertContains('kategori_subjek', $this->names('pengumpulan_data'));
        $this->assertContains('pemenuhan_hak_subjek', $this->names('informasi_pemrosesan'));
        $this->assertContains('pemenuhan_hak_detail', $this->names('informasi_pemrosesan'));
        $this->assertContains('pengendali_bersama_detail', $this->names('dpo_team'));
        $this->assertContains('pengendali_bersama_info_subjek', $this->names('dpo_team'));
    }

    #[Test]
    public function kategori_subjek_wajib(): void
    {
        $s = collect(RopaDefaultSchema::sections())->firstWhere('section_key', 'pengumpulan_data');
        $field = collect($s['fields'])->firstWhere('field_name', 'kategori_subjek');
        $this->assertTrue($field['is_required']);
    }

    #[Test]
    public function ropa_menyimpan_field_pp33_di_wizard_data(): void
    {
        $org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);

        $ropa = Ropa::create([
            'org_id' => $org->id,
            'processing_activity' => 'Payroll',
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -3),
            'wizard_data' => [
                'pengumpulan_data' => ['kategori_subjek' => ['Karyawan/Pegawai', 'Anak']],
                'informasi_pemrosesan' => ['pemenuhan_hak_subjek' => ['Akses & salinan', 'Penghapusan/pemusnahan']],
                'dpo_team' => ['pengendali_bersama_detail' => 'PT A & PT B, tanggung jawab dibagi per fungsi.'],
            ],
        ]);

        $fresh = $ropa->fresh();
        $this->assertContains('Anak', $fresh->wizard_data['pengumpulan_data']['kategori_subjek']);
        $this->assertContains('Akses & salinan', $fresh->wizard_data['informasi_pemrosesan']['pemenuhan_hak_subjek']);
        $this->assertNotEmpty($fresh->wizard_data['dpo_team']['pengendali_bersama_detail']);
    }
}
