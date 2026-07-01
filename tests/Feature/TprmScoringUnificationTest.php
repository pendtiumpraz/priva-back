<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;
use App\Services\CanonicalPdpLibraryService;
use App\Services\VendorHeadlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rework TPRM 2026-07 — verifikasi:
 *   A) Unifikasi Default(null) → library UU PDP kanonik (tak ada library_id null
 *      untuk UU PDP; UU PDP tak lagi terhitung dobel).
 *   B) Backfill idempotent: row library_id null → kanonik.
 *   C) Skor per-asesmen (per library) + headline vendor prefer UU PDP.
 */
class TprmScoringUnificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'PT Tenant Uji',
            'slug' => 'tenant-uji-'.Str::random(6),
        ]);

        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);

        $this->vendor = Vendor::create([
            'org_id' => $this->org->id,
            'name' => 'PT Vendor Pihak Ketiga',
            'category' => VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE,
            'pdp_scope_status' => Vendor::SCOPE_IN,
        ]);

        // Satu pertanyaan default PDP v2_2026 (org_id null) supaya provisioning
        // library kanonik punya soal untuk di-link.
        VendorQuestionnaire::create([
            'org_id' => null,
            'parent_id' => null,
            'category' => 'pdp_compliance',
            'version' => 'v2_2026',
            'question_code' => 'GOV-01',
            'section' => 'governance',
            'question_text' => 'Apakah pihak ketiga memiliki Kebijakan PDP?',
            'answer_type' => 'yes_no',
            'weight' => 5,
            'direction' => 1,
            'is_active' => true,
            'requires_evidence_upload' => false,
            'sort_order' => 1,
        ]);
    }

    private function canonicalId(): string
    {
        return app(CanonicalPdpLibraryService::class)->resolveId($this->org->id);
    }

    // =========================================================
    // A) Unifikasi — generate UU PDP → library_id kanonik (bukan null)
    // =========================================================

    public function test_generate_uu_pdp_resolves_to_canonical_library_not_null(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link")
            ->assertStatus(200)
            ->assertJsonPath('requires_pre_assessment', true);

        $assessment = VendorAssessment::where('assessment_token', $response->json('token'))->firstOrFail();

        $this->assertNotNull($assessment->library_id, 'UU PDP tidak boleh library_id null.');
        $this->assertSame($this->canonicalId(), $assessment->library_id);

        // Library kanonik = category pdp_compliance.
        $lib = QuestionLibrary::withoutGlobalScope('org')->find($assessment->library_id);
        $this->assertSame(VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE, $lib->category);
    }

    // =========================================================
    // A/C) Tidak dobel — null-Default & library UU PDP eksplisit = SATU jenis
    // =========================================================

    public function test_uu_pdp_not_double_counted_and_max_two_types(): void
    {
        Sanctum::actingAs($this->admin);

        // (1) generate tanpa library_id → resolve kanonik.
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link")
            ->assertStatus(200);

        // (2) generate dengan library_id kanonik EKSPLISIT → rotate row yang sama,
        //     BUKAN bikin row UU PDP kedua (inti fix bug "dobel").
        $canonical = $this->canonicalId();
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $canonical,
        ])->assertStatus(200);

        $pdpRows = VendorAssessment::where('vendor_id', $this->vendor->id)
            ->where('library_id', $canonical)
            ->count();
        $this->assertSame(1, $pdpRows, 'UU PDP harus tetap 1 row (tidak dobel).');

        // (3) jenis library lain (non-PDP) → row independen.
        $iso = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'ISO 27001', 'is_active' => true,
            'category' => 'iso_27001', 'version' => 'v1',
        ]);
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $iso->id,
        ])->assertStatus(200);

        // MAX 2 jenis untuk 2 library (UU PDP + ISO) — tidak ada 3.
        $this->assertSame(2, VendorAssessment::where('vendor_id', $this->vendor->id)->count());
    }

    // =========================================================
    // B) Backfill command — row library_id null → kanonik (idempotent)
    // =========================================================

    public function test_backfill_command_sets_null_rows_to_canonical(): void
    {
        $nullRow = VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'library_id' => null,
            'answers' => [],
            'status' => 'submitted',
            'score' => 70,
            'risk_level' => 'sedang',
        ]);

        $this->artisan('tprm:backfill-pdp-library-id')->assertExitCode(0);

        $nullRow->refresh();
        $this->assertSame($this->canonicalId(), $nullRow->library_id);

        // Idempotent — run kedua tidak error & tidak ada null tersisa.
        $this->artisan('tprm:backfill-pdp-library-id')->assertExitCode(0);
        $this->assertSame(0, VendorAssessment::whereNull('library_id')->count());
    }

    // =========================================================
    // C) Skor per-asesmen tersimpan per row + headline prefer UU PDP
    // =========================================================

    public function test_score_stored_per_row_and_headline_prefers_uu_pdp(): void
    {
        $canonical = $this->canonicalId();

        $iso = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'ISO 27001', 'is_active' => true,
            'category' => 'iso_27001', 'version' => 'v1',
        ]);

        // UU PDP submitted LEBIH DULU (skor 50, tinggi).
        $pdp = VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'library_id' => $canonical,
            'answers' => [],
            'status' => 'submitted',
            'score' => 50,
            'risk_level' => 'tinggi',
            'submitted_at' => now()->subDay(),
        ]);

        // ISO submitted BELAKANGAN dengan skor lebih tinggi (90, rendah).
        $isoAssessment = VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'library_id' => $iso->id,
            'answers' => [],
            'status' => 'submitted',
            'score' => 90,
            'risk_level' => 'rendah',
            'submitted_at' => now(),
        ]);

        // Skor per-asesmen: tiap row simpan skornya sendiri (tak dirata-rata).
        $this->assertSame(50, (int) $pdp->fresh()->score);
        $this->assertSame(90, (int) $isoAssessment->fresh()->score);

        app(VendorHeadlineService::class)->sync($this->vendor);

        // Headline = UU PDP (50/tinggi) walau ISO lebih baru & skornya lebih tinggi.
        $this->vendor->refresh();
        $this->assertSame(50, (int) $this->vendor->risk_score);
        $this->assertSame('tinggi', $this->vendor->risk_level);
    }

    public function test_headline_falls_back_to_latest_when_no_uu_pdp(): void
    {
        $iso = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'ISO 27001', 'is_active' => true,
            'category' => 'iso_27001', 'version' => 'v1',
        ]);

        VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'library_id' => $iso->id,
            'answers' => [],
            'status' => 'submitted',
            'score' => 88,
            'risk_level' => 'rendah',
            'submitted_at' => now(),
        ]);

        app(VendorHeadlineService::class)->sync($this->vendor);

        $this->vendor->refresh();
        $this->assertSame(88, (int) $this->vendor->risk_score);
        $this->assertSame('rendah', $this->vendor->risk_level);
    }

    public function test_show_exposes_per_assessment_score_and_assigned_libraries(): void
    {
        Sanctum::actingAs($this->admin);

        $canonical = $this->canonicalId();
        VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'library_id' => $canonical,
            'answers' => [],
            'status' => 'submitted',
            'score' => 65,
            'risk_level' => 'sedang',
            'submitted_at' => now(),
            'assessment_token' => (string) Str::uuid(),
            'token_consumed_at' => now(),
        ]);

        $response = $this->getJson("/api/vendor-risk/{$this->vendor->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.shareable_links.0.library_id', $canonical)
            ->assertJsonPath('data.shareable_links.0.score', 65)
            ->assertJsonPath('data.shareable_links.0.risk_level', 'sedang')
            ->assertJsonPath('data.shareable_links.0.requires_pre_assessment', true);

        $this->assertContains($canonical, $response->json('data.assigned_library_ids'));
    }
}
