<?php

namespace Tests\Unit;

use App\Models\GeneratedPolicy;
use App\Services\AiService;
use App\Services\PolicyGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit test for the Policy Generator core. AiService is constructor-injected so
 * it can be mocked — no real LLM call. Persistence + the deterministic
 * 15-element coverage + the mandatory legal-safety footer are asserted here.
 */
class PolicyGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_ID = '11111111-1111-1111-1111-111111111111';

    private const USER_ID = '22222222-2222-2222-2222-222222222222';

    private function fakeAiOutput(): array
    {
        // A privacy policy draft covering all 15 mandatory elements.
        return [
            'title' => 'Kebijakan Privasi PT Contoh',
            'metadata' => ['version' => '1.0'],
            'sections' => [
                ['type' => 'heading_1', 'text' => '1. Pengendali Data'],
                ['type' => 'paragraph', 'text' => 'PT Contoh selaku pengendali data pribadi bertanggung jawab atas pemrosesan data Anda.'],
                ['type' => 'paragraph', 'text' => 'Narahubung DPO (data protection officer) dapat dihubungi di dpo@contoh.id.'],
                ['type' => 'paragraph', 'text' => 'Kategori data yang dikumpulkan: nama, email, nomor telepon.'],
                ['type' => 'paragraph', 'text' => 'Tujuan pemrosesan adalah penyediaan layanan.'],
                ['type' => 'paragraph', 'text' => 'Dasar hukum pemrosesan mengacu Pasal 20 UU PDP.'],
                ['type' => 'paragraph', 'text' => 'Masa retensi data disimpan selama 5 tahun.'],
                ['type' => 'paragraph', 'text' => 'Kami berbagi data dengan pihak ketiga penyedia pembayaran.'],
                ['type' => 'paragraph', 'text' => 'Hak subjek data: hak akses, koreksi, penghapusan.'],
                ['type' => 'paragraph', 'text' => 'Anda dapat melakukan penarikan persetujuan kapan saja.'],
                ['type' => 'paragraph', 'text' => 'Langkah keamanan data: enkripsi dan kontrol akses.'],
                ['type' => 'paragraph', 'text' => 'Kebijakan cookie: kami menggunakan kuki untuk analitik.'],
                ['type' => 'paragraph', 'text' => 'Data anak di bawah umur memerlukan persetujuan orang tua.'],
                ['type' => 'paragraph', 'text' => 'Transfer data lintas negara dengan safeguard Pasal 56.'],
                ['type' => 'paragraph', 'text' => 'Pemberitahuan pelanggaran data sesuai Pasal 46.'],
                ['type' => 'paragraph', 'text' => 'Perubahan kebijakan akan diberitahukan dan versi kebijakan diperbarui.'],
            ],
        ];
    }

    private function mockAi(array $output): AiService
    {
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('isAvailable')->andReturn(true);
        $ai->shouldReceive('setLocale')->andReturnSelf();
        $ai->shouldReceive('ask')->andReturn($output);

        return $ai;
    }

    public function test_generate_persists_a_draft_policy_scoped_to_org(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(
            self::ORG_ID,
            self::USER_ID,
            GeneratedPolicy::AUDIENCE_CUSTOMER,
            'privacy_policy',
            'id',
            'Kebijakan Privasi PT Contoh',
            ['company_name' => 'PT Contoh', 'data_categories' => 'nama, email'],
        );

        $this->assertInstanceOf(GeneratedPolicy::class, $policy);
        $this->assertDatabaseHas('generated_policies', [
            'id' => $policy->id,
            'org_id' => self::ORG_ID,
            'created_by' => self::USER_ID,
            'audience' => 'customer',
            'language' => 'id',
            'document_type' => 'privacy_policy',
            'status' => 'draft',
        ]);
        $this->assertNotEmpty($policy->ai_output['sections']);
        $this->assertSame(['company_name' => 'PT Contoh', 'data_categories' => 'nama, email'], $policy->wizard_inputs);
    }

    public function test_generate_appends_mandatory_legal_disclaimer_footer(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);

        $blob = json_encode($policy->ai_output['sections'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsStringIgnoringCase('bukan nasihat hukum', $blob);
        $this->assertStringContainsStringIgnoringCase('tim legal', $blob);
        // Disclaimer must be the FINAL section so it always renders at the end.
        $sections = $policy->ai_output['sections'];
        $last = end($sections);
        $this->assertStringContainsStringIgnoringCase('nasihat hukum', json_encode($last, JSON_UNESCAPED_UNICODE));
    }

    public function test_generate_records_fifteen_element_coverage_in_metadata(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);

        $coverage = $policy->ai_metadata['coverage'] ?? null;
        $this->assertIsArray($coverage);
        $this->assertSame(15, $coverage['total']);
        $this->assertSame(15, $coverage['covered_count'], 'Uncovered: '.implode(', ', $coverage['missing'] ?? []));
        $this->assertTrue($coverage['all_covered']);
    }

    public function test_generate_flags_uncovered_elements_for_manual_review(): void
    {
        $thin = [
            'title' => 'Kebijakan Privasi',
            'sections' => [
                ['type' => 'paragraph', 'text' => 'PT Contoh pengendali data. Tujuan pemrosesan layanan. Hak subjek data tersedia.'],
            ],
        ];
        $service = new PolicyGeneratorService($this->mockAi($thin));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);

        $coverage = $policy->ai_metadata['coverage'];
        $this->assertFalse($coverage['all_covered']);
        $this->assertContains('kontak_dpo', $coverage['missing']);
        $this->assertNotEmpty($policy->ai_metadata['needs_manual_review']);
    }

    public function test_clause_sources_reflect_actual_coverage(): void
    {
        $thin = [
            'title' => 'Kebijakan Privasi',
            'sections' => [
                ['type' => 'paragraph', 'text' => 'PT Contoh pengendali data. Tujuan pemrosesan layanan. Hak subjek data tersedia.'],
            ],
        ];
        $service = new PolicyGeneratorService($this->mockAi($thin));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);

        $byElement = collect($policy->ai_metadata['clause_sources'])->keyBy('element');
        // Covered element → covered=true + a grounding source.
        $this->assertTrue($byElement['hak_subjek']['covered']);
        $this->assertNotNull($byElement['hak_subjek']['source']);
        // Absent element → covered=false + source null (distinguishes "absent → manual review").
        $this->assertFalse($byElement['kontak_dpo']['covered']);
        $this->assertNull($byElement['kontak_dpo']['source']);
    }

    public function test_ensure_legal_footer_is_idempotent(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        // Absent → appended once, at the end.
        $withFooter = $service->ensureLegalFooter(['sections' => [['type' => 'paragraph', 'text' => 'Isi.']]]);
        $disclaimers = collect($withFooter['sections'])->where('role', 'legal_disclaimer');
        $this->assertCount(1, $disclaimers);
        $this->assertSame('legal_disclaimer', end($withFooter['sections'])['role']);

        // Already present → not duplicated.
        $again = $service->ensureLegalFooter($withFooter);
        $this->assertCount(1, collect($again['sections'])->where('role', 'legal_disclaimer'));
    }

    public function test_employee_audience_scopes_coverage_to_thirteen_elements(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'employee', 'privacy_policy', 'id', 'Kebijakan Privasi Karyawan', ['x' => 1]);

        $this->assertSame('employee', $policy->audience);
        $coverage = $policy->ai_metadata['coverage'];
        $this->assertSame(13, $coverage['total']);
        $this->assertContains('cookie', $coverage['not_applicable']);
        $this->assertContains('data_anak', $coverage['not_applicable']);
        // The fixture covers everything → all 13 applicable elements covered.
        $this->assertTrue($coverage['all_covered']);
    }

    public function test_english_output_uses_english_disclaimer_and_flags_linguist_review(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'en', 'Privacy Policy', ['x' => 1]);

        $this->assertSame('en', $policy->language);
        $blob = json_encode($policy->ai_output['sections'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('NOT legal advice', $blob);
        $this->assertTrue($policy->ai_metadata['needs_legal_linguist_review']);
    }

    public function test_generate_records_per_section_confidence(): void
    {
        $service = new PolicyGeneratorService($this->mockAi($this->fakeAiOutput()));

        $policy = $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);

        $confidence = $policy->ai_metadata['confidence'] ?? null;
        $this->assertIsArray($confidence);
        $this->assertArrayHasKey('overall', $confidence);
        $this->assertArrayHasKey('sections', $confidence);
        $this->assertNotEmpty($confidence['sections']);
        // Indonesian default → disclaimer flag false.
        $this->assertFalse($policy->ai_metadata['needs_legal_linguist_review']);
    }

    public function test_generate_throws_when_ai_returns_invalid_structure(): void
    {
        $service = new PolicyGeneratorService($this->mockAi(['raw' => 'not json']));

        $this->expectException(\RuntimeException::class);
        $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);
    }

    public function test_generate_throws_when_ai_unavailable(): void
    {
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('isAvailable')->andReturn(false);
        $ai->shouldReceive('setLocale')->andReturnSelf();
        $ai->shouldNotReceive('ask');

        $service = new PolicyGeneratorService($ai);

        $this->expectException(\RuntimeException::class);
        $service->generate(self::ORG_ID, self::USER_ID, 'customer', 'privacy_policy', 'id', 'Judul', ['x' => 1]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
