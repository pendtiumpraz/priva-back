<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorAssessmentEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unduh berkas PRIVAT TPRM:
 *   - GET /api/tprm/review/{id}/evidence/{evidenceId}     (bukti asesmen)
 *   - GET /api/vendor-risk/{id}/intake-documents/{kind}   (dokumen intake)
 *
 * Fokus test: ISOLASI ORG. Kedua endpoint men-stream berkas dari storage
 * privat per-tenant, jadi kebocoran lintas tenant di sini fatal. Yang
 * dibuktikan:
 *   (a) pemilik org bisa mengunduh isi berkasnya;
 *   (b) user org LAIN mendapat 404 (tidak bisa membaca berkas org lain);
 *   (c) id/kind tak dikenal juga 404 — respons identik dengan (b) sehingga
 *       tidak membocorkan eksistensi berkas milik org lain.
 */
class TprmPrivateFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    private User $userB;

    private Vendor $vendorA;

    private VendorAssessment $assessmentA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->orgA = Organization::create([
            'name' => 'PT Tenant A',
            'slug' => 'tenant-a-'.Str::random(6),
        ]);
        $this->orgB = Organization::create([
            'name' => 'PT Tenant B',
            'slug' => 'tenant-b-'.Str::random(6),
        ]);

        $this->userA = User::factory()->create(['org_id' => $this->orgA->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['org_id' => $this->orgB->id, 'role' => 'admin']);

        $this->vendorA = Vendor::create([
            'org_id' => $this->orgA->id,
            'name' => 'PT Pihak Ketiga A',
        ]);

        $this->assessmentA = VendorAssessment::create([
            'vendor_id' => $this->vendorA->id,
            'org_id' => $this->orgA->id,
            'answers' => [],
            'status' => 'submitted',
        ]);
    }

    private function makeEvidence(): VendorAssessmentEvidence
    {
        $path = "tenants/{$this->orgA->id}/tprm/assessments/{$this->assessmentA->id}/evidence/bukti.pdf";
        Storage::disk(config('filesystems.default'))->put($path, 'ISI-BUKTI-RAHASIA');

        return VendorAssessmentEvidence::create([
            'org_id' => $this->orgA->id,
            'assessment_id' => $this->assessmentA->id,
            'question_id' => (string) Str::uuid(),
            'file_path' => $path,
            'original_name' => 'bukti.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 18,
            'uploaded_by_token' => true,
            'is_active' => true,
        ]);
    }

    private function makeIntakeDocument(): string
    {
        $path = "tenants/{$this->orgA->id}/vendors/{$this->vendorA->id}/documents/akta.pdf";
        Storage::disk(config('filesystems.default'))->put($path, 'ISI-AKTA-RAHASIA');

        $this->vendorA->update(['documents' => [
            'akta_notaris' => [
                'path' => $path,
                'driver' => 'local',
                'filename' => 'akta.pdf',
                'size' => 17,
                'uploaded_at' => now()->toIso8601String(),
            ],
        ]]);

        return $path;
    }

    // =========================================================
    // Bukti asesmen TPRM
    // =========================================================

    public function test_owner_org_can_download_assessment_evidence(): void
    {
        $evidence = $this->makeEvidence();
        Sanctum::actingAs($this->userA);

        $response = $this->get("/api/tprm/review/{$this->assessmentA->id}/evidence/{$evidence->id}");

        $response->assertOk();
        $this->assertSame('ISI-BUKTI-RAHASIA', $response->streamedContent());
        $this->assertStringContainsString('bukti.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_other_org_cannot_download_assessment_evidence(): void
    {
        $evidence = $this->makeEvidence();
        Sanctum::actingAs($this->userB);

        // Org B menebak id assessment + id evidence milik org A: tetap 404.
        $this->get("/api/tprm/review/{$this->assessmentA->id}/evidence/{$evidence->id}")
            ->assertNotFound();
    }

    public function test_unknown_evidence_id_does_not_leak_existence(): void
    {
        $evidence = $this->makeEvidence();

        // Assessment milik org B (berbeda), tapi evidenceId milik org A →
        // 404, sama seperti id acak. Tidak ada perbedaan status/pesan yang
        // bisa dipakai menyimpulkan berkas itu ada.
        $assessmentB = VendorAssessment::create([
            'vendor_id' => Vendor::create(['org_id' => $this->orgB->id, 'name' => 'PT Pihak Ketiga B'])->id,
            'org_id' => $this->orgB->id,
            'answers' => [],
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->userB);

        $crossRef = $this->get("/api/tprm/review/{$assessmentB->id}/evidence/{$evidence->id}");
        $random = $this->get('/api/tprm/review/'.$assessmentB->id.'/evidence/'.Str::uuid());

        $crossRef->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($random->getStatusCode(), $crossRef->getStatusCode());
    }

    // =========================================================
    // Dokumen intake pihak ketiga
    // =========================================================

    public function test_owner_org_can_download_intake_document(): void
    {
        $this->makeIntakeDocument();
        Sanctum::actingAs($this->userA);

        $response = $this->get("/api/vendor-risk/{$this->vendorA->id}/intake-documents/akta_notaris");

        $response->assertOk();
        $this->assertSame('ISI-AKTA-RAHASIA', $response->streamedContent());
        $this->assertStringContainsString('akta.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_other_org_cannot_download_intake_document(): void
    {
        $this->makeIntakeDocument();
        Sanctum::actingAs($this->userB);

        $this->get("/api/vendor-risk/{$this->vendorA->id}/intake-documents/akta_notaris")
            ->assertNotFound();
    }

    public function test_unknown_vendor_or_kind_does_not_leak_existence(): void
    {
        $this->makeIntakeDocument();
        Sanctum::actingAs($this->userA);

        // Kind valid tapi belum diunggah → 404 (bukan 500/200 kosong).
        $this->get("/api/vendor-risk/{$this->vendorA->id}/intake-documents/ktp")
            ->assertNotFound();

        // Kind di luar whitelist → 404, tidak ada traversal ke key JSON lain.
        $this->get("/api/vendor-risk/{$this->vendorA->id}/intake-documents/rahasia")
            ->assertNotFound();

        // Vendor id acak → 404, sama seperti vendor milik org lain.
        $this->get('/api/vendor-risk/'.Str::uuid().'/intake-documents/akta_notaris')
            ->assertNotFound();
    }
}
