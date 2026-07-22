<?php

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract Review + Policy Review:
 *   1. Ekspor PDF ber-branding (AssessmentPdfService) — berhasil untuk pemilik
 *      org, 404 untuk org lain (invarian multi-tenancy org_id).
 *   2. Kolom sumber Document Maker (`source_document_id` / `source_module`)
 *      tersimpan dan muncul di response detail beserta judul dokumen sumber.
 *   3. Record lama (kolom sumber NULL) tetap aman — detail + export jalan.
 */
class ReviewExportAndSourceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::create([
            'name' => 'Review Tenant A',
            'slug' => 'review-a-'.Str::random(6),
        ]);
        $this->orgB = Organization::create([
            'name' => 'Review Tenant B',
            'slug' => 'review-b-'.Str::random(6),
        ]);

        $this->userA = User::factory()->create(['org_id' => $this->orgA->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['org_id' => $this->orgB->id, 'role' => 'admin']);
    }

    private function contractResult(): array
    {
        return [
            'overall_rating' => 'perlu_perbaikan',
            'risk_score' => 62,
            'summary' => 'Kontrak belum memuat klausul retensi yang jelas.',
            'findings' => [[
                'clause' => 'Kerahasiaan',
                'issue' => 'Tidak menyebut jangka waktu.',
                'risk_level' => 'medium',
                'recommendation' => 'Tambahkan jangka waktu kerahasiaan.',
                'uu_pdp_reference' => 'Pasal 35',
            ]],
            'missing_clauses' => ['masa retensi'],
            'compliance_checklist' => [
                'klausul_tujuan_pemrosesan' => true,
                'masa_retensi' => false,
            ],
        ];
    }

    private function policyResult(): array
    {
        return [
            'overall_score' => 74,
            'compliance_level' => 'partial',
            'summary' => 'Kebijakan sudah baik namun retensi belum diatur.',
            'sections' => [[
                'section_title' => 'Hak Subjek Data',
                'status' => 'partial',
                'score' => 60,
                'gap_description' => 'Belum ada kanal pengajuan.',
                'recommendation' => 'Sediakan kanal resmi.',
                'uu_pdp_reference' => 'Pasal 5',
            ]],
            'missing_elements' => ['prosedur retensi'],
            'strengths' => ['Dasar hukum jelas'],
            'priority_actions' => [[
                'action' => 'Susun SOP retensi',
                'priority' => 'high',
                'deadline_suggestion' => '1 month',
            ]],
        ];
    }

    private function makeContractReview(string $orgId, ?string $sourceDocumentId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('contract_reviews')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'title' => 'Perjanjian Kerja Sama',
            'contract_type' => 'dpa',
            'contract_text' => str_repeat('isi kontrak ', 20),
            'review_result' => json_encode($this->contractResult()),
            'risk_score' => 62,
            'overall_rating' => 'perlu_perbaikan',
            'status' => 'completed',
            'source_document_id' => $sourceDocumentId,
            'source_module' => $sourceDocumentId ? 'document_maker' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makePolicyReview(string $orgId, ?string $sourceDocumentId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('policy_reviews')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'title' => 'Kebijakan Privasi Internal',
            'doc_type' => 'kebijakan_privasi',
            'extracted_text' => str_repeat('isi kebijakan ', 20),
            'review_result' => json_encode($this->policyResult()),
            'risk_score' => 74,
            'status' => 'completed',
            'source_document_id' => $sourceDocumentId,
            'source_module' => $sourceDocumentId ? 'document_maker' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeGeneratedDocument(string $orgId, string $userId, string $kind): GeneratedDocument
    {
        return GeneratedDocument::withoutGlobalScopes()->create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'kind' => $kind,
            'document_type' => $kind === 'contract' ? 'nda' : 'privacy_policy',
            'title' => 'Draft dari Document Maker',
            'wizard_inputs' => ['company' => 'PT Uji'],
            'ai_output' => ['sections' => [['type' => 'paragraph', 'text' => 'Isi dokumen.']]],
        ]);
    }

    // =============================================
    // 1. Export PDF — org scoping
    // =============================================

    public function test_contract_review_export_returns_pdf_for_owning_org(): void
    {
        $id = $this->makeContractReview($this->orgA->id);

        Sanctum::actingAs($this->userA);
        $res = $this->get("/api/contract-reviews/{$id}/export.pdf");

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', substr($res->getContent(), 0, 4));
    }

    public function test_contract_review_export_denied_for_other_org(): void
    {
        $id = $this->makeContractReview($this->orgA->id);

        Sanctum::actingAs($this->userB);
        $this->getJson("/api/contract-reviews/{$id}/export.pdf")->assertStatus(404);
    }

    public function test_policy_review_export_returns_pdf_for_owning_org(): void
    {
        $id = $this->makePolicyReview($this->orgA->id);

        Sanctum::actingAs($this->userA);
        $res = $this->get("/api/policy-reviews/{$id}/export.pdf");

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', substr($res->getContent(), 0, 4));
    }

    public function test_policy_review_export_denied_for_other_org(): void
    {
        $id = $this->makePolicyReview($this->orgA->id);

        Sanctum::actingAs($this->userB);
        $this->getJson("/api/policy-reviews/{$id}/export.pdf")->assertStatus(404);
    }

    public function test_export_requires_authentication(): void
    {
        $id = $this->makeContractReview($this->orgA->id);

        $this->getJson("/api/contract-reviews/{$id}/export.pdf")->assertStatus(401);
    }

    // =============================================
    // 2. Kolom sumber Document Maker
    // =============================================

    public function test_contract_review_detail_exposes_source_document(): void
    {
        $doc = $this->makeGeneratedDocument($this->orgA->id, $this->userA->id, 'contract');
        $id = $this->makeContractReview($this->orgA->id, $doc->id);

        Sanctum::actingAs($this->userA);
        $res = $this->getJson("/api/contract-reviews/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.source_document_id', $doc->id)
            ->assertJsonPath('data.source_module', 'document_maker')
            ->assertJsonPath('data.source_document_title', 'Draft dari Document Maker');
    }

    public function test_policy_review_detail_exposes_source_document(): void
    {
        $doc = $this->makeGeneratedDocument($this->orgA->id, $this->userA->id, 'policy');
        $id = $this->makePolicyReview($this->orgA->id, $doc->id);

        Sanctum::actingAs($this->userA);
        $res = $this->getJson("/api/policy-reviews/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.source_document_id', $doc->id)
            ->assertJsonPath('data.source_module', 'document_maker')
            ->assertJsonPath('data.source_document_title', 'Draft dari Document Maker');
    }

    /**
     * Dokumen sumber milik org lain tidak boleh bocor judulnya.
     */
    public function test_source_document_title_not_resolved_across_orgs(): void
    {
        $docB = $this->makeGeneratedDocument($this->orgB->id, $this->userB->id, 'contract');
        $id = $this->makeContractReview($this->orgA->id, $docB->id);

        Sanctum::actingAs($this->userA);
        $this->getJson("/api/contract-reviews/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.source_document_title', null);
    }

    // =============================================
    // 3. Record lama (kolom sumber NULL) tetap aman
    // =============================================

    public function test_legacy_records_without_source_document_still_work(): void
    {
        $contractId = $this->makeContractReview($this->orgA->id, null);
        $policyId = $this->makePolicyReview($this->orgA->id, null);

        Sanctum::actingAs($this->userA);

        $this->getJson("/api/contract-reviews/{$contractId}")
            ->assertStatus(200)
            ->assertJsonPath('data.source_document_id', null)
            ->assertJsonPath('data.source_module', null)
            ->assertJsonPath('data.source_document_title', null);

        $this->getJson("/api/policy-reviews/{$policyId}")
            ->assertStatus(200)
            ->assertJsonPath('data.source_document_id', null)
            ->assertJsonPath('data.source_document_title', null);

        $this->get("/api/contract-reviews/{$contractId}/export.pdf")->assertStatus(200);
        $this->get("/api/policy-reviews/{$policyId}/export.pdf")->assertStatus(200);
    }

    /**
     * Migrasi nullable tanpa default: kolom baru boleh dilewatkan sama sekali
     * pada INSERT (persis seperti kode/dumps lama yang belum tahu kolom ini).
     */
    public function test_insert_without_new_columns_is_safe(): void
    {
        $id = (string) Str::uuid();
        DB::table('contract_reviews')->insert([
            'id' => $id,
            'org_id' => $this->orgA->id,
            'title' => 'Kontrak Lama',
            'contract_type' => 'other',
            'review_result' => json_encode($this->contractResult()),
            'risk_score' => 10,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('contract_reviews')->where('id', $id)->first();
        $this->assertNull($row->source_document_id);
        $this->assertNull($row->source_module);
    }
}
