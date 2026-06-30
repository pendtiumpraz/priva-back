<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorPreAssessment;
use App\Models\VendorQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint G.10 — Integration tests for the TPRM public assessment token flow.
 *
 * Covers admin-side token generation (G.6), pihak-ketiga (third-party) GET +
 * draft save + evidence upload + submit + scoring (G.5), plus middleware
 * guards (expired/consumed/invalid token) and FileUploadValidator rejection.
 *
 * The Sprint G public flow is entirely token-mediated and runs through the
 * `public-assessment-token` middleware which: resolves the token, validates
 * expiry, blocks write methods after consumption, sets tenant context, and
 * rate-limits 30 RPM. These tests exercise that middleware end-to-end against
 * real DB rows so regressions in either the middleware OR the controller get
 * surfaced together.
 */
class PublicAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        // Disk fake — TenantStorageService writes through the resolved disk,
        // which for tests falls back to Laravel's default. Faking ensures
        // file persistence is captured without polluting the real storage.
        Storage::fake('local');

        $this->org = Organization::create([
            'name' => 'PT Tenant Uji',
            'slug' => 'tenant-uji-'.Str::random(6),
        ]);

        // Admin tenant — pakai role admin yang lolos legacy fallback di
        // CheckPermission (tenantRole->permissions tidak di-set jadi
        // legacy admin/dpo/maker langsung write-allowed).
        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);

        // Vendor target asesmen.
        $this->vendor = Vendor::create([
            'org_id' => $this->org->id,
            'name' => 'PT Vendor Pihak Ketiga',
            'category' => VendorQuestionnaire::CATEGORY_CLOUD,
        ]);

        // Seed satu pertanyaan v2_2026 supaya effectiveForOrg() return non-empty.
        // Pakai DB::table langsung biar lolos LandlordPinned (no-op di testing,
        // tapi tetap aman) dan tidak butuh seluruh ThirdPartyQuestionnaireSeeder.
        VendorQuestionnaire::create([
            'org_id' => null,
            'parent_id' => null,
            'category' => 'pdp_compliance',
            'version' => 'v2_2026',
            'question_code' => 'GOV-01',
            'section' => 'governance',
            'question_text' => 'Apakah pihak ketiga memiliki Kebijakan PDP?',
            'description' => 'Kebijakan tertulis yang disetujui manajemen.',
            'regulation_ref' => 'UU PDP Pasal 35',
            'recommendation_if_no' => 'Susun Kebijakan PDP formal.',
            'answer_type' => 'yes_no',
            'weight' => 5,
            'direction' => 1,
            'is_active' => true,
            'requires_evidence_upload' => false,
            'sort_order' => 1,
        ]);
    }

    // =========================================================
    // Admin-side: generate-public-link
    // =========================================================

    public function test_admin_can_generate_public_link(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message', 'assessment_id', 'token', 'public_url', 'expires_at',
            ]);

        $token = $response->json('token');
        $this->assertTrue(Str::isUuid($token), 'Token harus UUID valid.');

        $assessment = VendorAssessment::where('assessment_token', $token)->firstOrFail();
        $this->assertSame('sent', $assessment->status);
        // Kebijakan revisi 2026-06-30: tautan publik TIDAK kedaluwarsa by-time —
        // token_expires_at null. Tautan hanya invalid saat di-regenerate.
        $this->assertNull($assessment->token_expires_at, 'Tautan publik tidak boleh expiry by-time.');
    }

    // =========================================================
    // Revisi #5 — multi-assessment per vendor by jenis (library_id)
    // =========================================================

    public function test_generate_link_per_library_independent_tokens(): void
    {
        Sanctum::actingAs($this->admin);

        $libA = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library A', 'is_active' => true,
        ]);
        $libB = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library B', 'is_active' => true,
        ]);

        // (a) generate jenis A → token A
        $tokenA = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id,
        ])->assertStatus(200)->json('token');

        // generate jenis B → token B (row + token independen)
        $tokenB = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libB->id,
        ])->assertStatus(200)->json('token');

        $this->assertNotSame($tokenA, $tokenB);
        // A masih valid setelah B dibuat.
        $this->getJson('/api/asesmen-publik/'.$tokenA)->assertStatus(200);
        $this->getJson('/api/asesmen-publik/'.$tokenB)->assertStatus(200);

        // Dua row terpisah, satu per library.
        $this->assertSame(2, VendorAssessment::where('vendor_id', $this->vendor->id)->count());
    }

    public function test_regenerate_rotates_same_library_and_keeps_other(): void
    {
        Sanctum::actingAs($this->admin);

        $libA = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library A', 'is_active' => true,
        ]);
        $libB = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library B', 'is_active' => true,
        ]);

        $tokenA1 = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id,
        ])->json('token');
        $tokenB = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libB->id,
        ])->json('token');

        // (b) regenerate A → token A baru, token A lama invalid, B tetap.
        $tokenA2 = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id,
        ])->assertStatus(200)->json('token');

        $this->assertNotSame($tokenA1, $tokenA2, 'Regenerate harus rotate token.');
        $this->getJson('/api/asesmen-publik/'.$tokenA1)->assertStatus(404); // lama invalid
        $this->getJson('/api/asesmen-publik/'.$tokenA2)->assertStatus(200); // baru valid
        $this->getJson('/api/asesmen-publik/'.$tokenB)->assertStatus(200);  // B tak tersentuh

        // Rotate = row sama, bukan duplikat: tetap 2 row total (A + B).
        $this->assertSame(2, VendorAssessment::where('vendor_id', $this->vendor->id)->count());
    }

    public function test_submitted_library_blocks_regenerate_but_other_library_ok(): void
    {
        Sanctum::actingAs($this->admin);

        $libA = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library A', 'is_active' => true,
        ]);
        $libB = QuestionLibrary::create([
            'org_id' => $this->org->id, 'name' => 'Library B', 'is_active' => true,
        ]);

        $tokenA = $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id,
        ])->json('token');

        // Vendor submit jenis A.
        $assessmentA = VendorAssessment::where('assessment_token', $tokenA)->firstOrFail();
        $assessmentA->forceFill(['status' => 'submitted', 'token_consumed_at' => now()])->save();

        // (c) generate A lagi → diblok (per-library guard).
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id,
        ])->assertStatus(422);

        // reassess=true → boleh bikin siklus baru jenis A.
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libA->id, 'reassess' => true,
        ])->assertStatus(200);

        // Jenis B tetap bisa digenerate meski A sudah submitted.
        $this->postJson("/api/vendor-risk/{$this->vendor->id}/generate-public-link", [
            'library_id' => $libB->id,
        ])->assertStatus(200);
    }

    // =========================================================
    // Pihak ketiga (public token) flow
    // =========================================================

    public function test_pihak_ketiga_can_access_public_page(): void
    {
        $assessment = $this->makeSentAssessment();

        $response = $this->getJson('/api/asesmen-publik/'.$assessment->assessment_token);

        $response->assertStatus(200)
            ->assertJsonPath('data.assessment.id', $assessment->id)
            ->assertJsonPath('data.assessment.is_locked', false)
            ->assertJsonPath('data.vendor.id', $this->vendor->id);

        // Question list harus terisi minimal 1 row (kita seed GOV-01 di setUp).
        $this->assertGreaterThanOrEqual(1, count($response->json('data.questions')));
    }

    public function test_pihak_ketiga_can_save_draft_answers(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;

        $response = $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/jawaban', [
            'answers' => [
                $qid => ['value' => 'ya', 'note' => 'Sudah ada SOP'],
            ],
        ]);

        $response->assertStatus(200);

        $assessment->refresh();
        $this->assertSame('ya', $assessment->answers[$qid]['value']);
        $this->assertSame('Sudah ada SOP', $assessment->answers[$qid]['note']);
    }

    public function test_pihak_ketiga_can_upload_evidence(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;

        $pdf = UploadedFile::fake()->createWithContent('bukti.pdf', $this->fakePdfBytes());

        $response = $this->postJson(
            '/api/asesmen-publik/'.$assessment->assessment_token.'/upload',
            ['question_id' => $qid, 'file' => $pdf],
        );

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'path', 'original_name', 'size']]);

        $assessment->refresh();
        $this->assertNotEmpty($assessment->answers[$qid]['evidence'] ?? []);
        $this->assertSame('bukti.pdf', $assessment->answers[$qid]['evidence'][0]['original_name']);
    }

    public function test_pihak_ketiga_submit_triggers_scoring(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;

        // Pre-populate jawaban 'ya' → score 100% karena cuma 1 pertanyaan aktif.
        $assessment->forceFill([
            'answers' => [$qid => ['value' => 'ya']],
        ])->save();

        $response = $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/submit');

        $response->assertStatus(200)
            ->assertJsonPath('data.assessment_id', $assessment->id);

        $assessment->refresh();
        $this->assertSame('submitted', $assessment->status);
        $this->assertNotNull($assessment->token_consumed_at);
        $this->assertNotNull($assessment->submitted_at);
        $this->assertSame(100, (int) $assessment->score);
        $this->assertSame('rendah', $assessment->risk_level);
        $this->assertIsArray($assessment->recommendations);
    }

    public function test_double_submit_returns_410(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;
        $assessment->forceFill(['answers' => [$qid => ['value' => 'ya']]])->save();

        // Submit pertama berhasil.
        $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/submit')->assertStatus(200);

        // Submit kedua di-block middleware (single-use guard) → HTTP 410 Gone.
        $second = $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/submit');
        $second->assertStatus(410);
    }

    public function test_expired_token_returns_410(): void
    {
        $assessment = $this->makeSentAssessment();
        $assessment->forceFill(['token_expires_at' => now()->subDay()])->save();

        $response = $this->getJson('/api/asesmen-publik/'.$assessment->assessment_token);
        $response->assertStatus(410);
    }

    public function test_invalid_token_returns_404(): void
    {
        $randomUuid = (string) Str::uuid();
        $response = $this->getJson('/api/asesmen-publik/'.$randomUuid);
        $response->assertStatus(404);
    }

    public function test_invalid_file_ext_returns_422(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;

        // .exe ada di whitelist eksplisit controller (4-format-only) — ditolak
        // sebelum sampai ke FileUploadValidator. Test path "wrong extension".
        $exe = UploadedFile::fake()->create('malware.exe', 100);
        $response = $this->postJson(
            '/api/asesmen-publik/'.$assessment->assessment_token.'/upload',
            ['question_id' => $qid, 'file' => $exe],
        );
        $response->assertStatus(422);
    }

    public function test_consumed_token_can_view_result(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;
        $assessment->forceFill(['answers' => [$qid => ['value' => 'ya']]])->save();

        $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/submit')->assertStatus(200);

        $response = $this->getJson('/api/asesmen-publik/'.$assessment->assessment_token.'/result');

        $response->assertStatus(200)
            ->assertJsonPath('data.assessment_id', $assessment->id)
            ->assertJsonStructure([
                'data' => ['score', 'risk_level', 'recommendations', 'summary'],
            ]);
    }

    // =========================================================
    // Revisi #1 + #6 — vendor self-fill profil + upload dokumen via token publik
    // =========================================================

    public function test_pihak_ketiga_can_update_profil(): void
    {
        $assessment = $this->makeSentAssessment();

        $response = $this->putJson('/api/asesmen-publik/'.$assessment->assessment_token.'/profil', [
            'name' => 'PT Vendor Update Sendiri',
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@vendor.test',
            'website' => 'https://vendor.test',
            'country' => 'Indonesia',
            'jenis_entitas' => 'badan_hukum',
            'bidang' => ['IT', 'Legal'],
            'departemen_kontak' => 'Procurement',
            'npwp' => '09.254.294.3-407.000',
            'alamat' => 'Jl. Merdeka No. 1, Jakarta Pusat',
            'telepon' => '+62-21-5550123',
            'pic_jabatan' => 'Manajer Legal',
            'description' => 'Penyedia layanan cloud.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'PT Vendor Update Sendiri')
            ->assertJsonPath('data.contact_name', 'Budi Santoso')
            // Field terenkripsi harus kembali ter-decrypt di response.
            ->assertJsonPath('data.npwp', '09.254.294.3-407.000')
            ->assertJsonPath('data.alamat', 'Jl. Merdeka No. 1, Jakarta Pusat')
            ->assertJsonPath('data.telepon', '+62-21-5550123')
            ->assertJsonPath('data.pic_jabatan', 'Manajer Legal');

        $this->vendor->refresh();
        $this->assertSame('PT Vendor Update Sendiri', $this->vendor->name);
        $this->assertSame('budi@vendor.test', $this->vendor->contact_email);
        $this->assertSame(['IT', 'Legal'], $this->vendor->bidang);
        $this->assertSame('badan_hukum', $this->vendor->jenis_entitas);
        // Revisi #1/#6 — profil identitas legal tersimpan & ter-decrypt.
        $this->assertSame('09.254.294.3-407.000', $this->vendor->npwp);
        $this->assertSame('Jl. Merdeka No. 1, Jakarta Pusat', $this->vendor->alamat);
        $this->assertSame('+62-21-5550123', $this->vendor->telepon);
        $this->assertSame('Manajer Legal', $this->vendor->pic_jabatan);

        // npwp + telepon harus tersimpan terenkripsi (ciphertext != plaintext)
        // di kolom raw — buktikan EncryptedString cast aktif.
        $raw = DB::table('vendors')
            ->where('id', $this->vendor->id)
            ->first(['npwp', 'telepon', 'alamat']);
        $this->assertNotSame('09.254.294.3-407.000', $raw->npwp, 'npwp harus terenkripsi at-rest.');
        $this->assertNotSame('+62-21-5550123', $raw->telepon, 'telepon harus terenkripsi at-rest.');
        // alamat sengaja TIDAK di-encrypt (free text) — tersimpan apa adanya.
        $this->assertSame('Jl. Merdeka No. 1, Jakarta Pusat', $raw->alamat);
    }

    public function test_pihak_ketiga_profil_requires_name(): void
    {
        $assessment = $this->makeSentAssessment();

        $this->putJson('/api/asesmen-publik/'.$assessment->assessment_token.'/profil', [
            'contact_email' => 'budi@vendor.test',
        ])->assertStatus(422);
    }

    public function test_pihak_ketiga_can_upload_dokumen(): void
    {
        $assessment = $this->makeSentAssessment();

        $pdf = UploadedFile::fake()->createWithContent('akta.pdf', $this->fakePdfBytes());

        $response = $this->postJson(
            '/api/asesmen-publik/'.$assessment->assessment_token.'/dokumen',
            ['kind' => 'akta_notaris', 'file' => $pdf],
        );

        $response->assertStatus(201)
            ->assertJsonPath('kind', 'akta_notaris')
            ->assertJsonStructure(['document' => ['path', 'filename', 'size', 'uploaded_at', 'uploaded_by_token']]);

        $this->vendor->refresh();
        $this->assertArrayHasKey('akta_notaris', $this->vendor->documents);
        $this->assertSame('akta.pdf', $this->vendor->documents['akta_notaris']['filename']);
        $this->assertNull($this->vendor->documents['akta_notaris']['uploaded_by']);
        $this->assertTrue($this->vendor->documents['akta_notaris']['uploaded_by_token']);
    }

    public function test_pihak_ketiga_dokumen_rejects_bad_kind(): void
    {
        $assessment = $this->makeSentAssessment();
        $pdf = UploadedFile::fake()->createWithContent('x.pdf', $this->fakePdfBytes());

        $this->postJson(
            '/api/asesmen-publik/'.$assessment->assessment_token.'/dokumen',
            ['kind' => 'random_kind', 'file' => $pdf],
        )->assertStatus(422);
    }

    public function test_profil_and_dokumen_blocked_after_consumed(): void
    {
        $assessment = $this->makeSentAssessment();
        $qid = VendorQuestionnaire::whereNull('org_id')->where('version', 'v2_2026')->first()->id;
        $assessment->forceFill(['answers' => [$qid => ['value' => 'ya']]])->save();

        // Submit → token consumed.
        $this->postJson('/api/asesmen-publik/'.$assessment->assessment_token.'/submit')->assertStatus(200);

        // Write profil setelah consumed → 410 (single-use guard middleware).
        $this->putJson('/api/asesmen-publik/'.$assessment->assessment_token.'/profil', [
            'name' => 'Coba Ubah Lagi',
        ])->assertStatus(410);

        // Upload dokumen setelah consumed → 410.
        $pdf = UploadedFile::fake()->createWithContent('akta.pdf', $this->fakePdfBytes());
        $this->postJson(
            '/api/asesmen-publik/'.$assessment->assessment_token.'/dokumen',
            ['kind' => 'akta_notaris', 'file' => $pdf],
        )->assertStatus(410);
    }

    // =========================================================
    // Revisi #2 — pra-asesmen public link juga tak kadaluarsa by-time
    // =========================================================

    public function test_pre_assessment_public_link_has_no_expiry(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/vendor-risk/{$this->vendor->id}/pre-assessment/public-link");

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'pre_assessment_id', 'token', 'public_url', 'expires_at']);

        $this->assertNull($response->json('expires_at'), 'Token pra-asesmen tidak boleh expiry by-time.');

        $pre = VendorPreAssessment::where('assessment_token', $response->json('token'))->firstOrFail();
        $this->assertNull($pre->token_expires_at);
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Bikin assessment yang sudah punya token aktif (status='sent') seperti
     * baru saja di-generate oleh admin. Memotong langkah POST generate-link
     * supaya test kasus berikutnya fokus pada flow publik saja.
     */
    private function makeSentAssessment(): VendorAssessment
    {
        return VendorAssessment::create([
            'vendor_id' => $this->vendor->id,
            'org_id' => $this->org->id,
            'answers' => [],
            'status' => 'sent',
            'assessment_token' => (string) Str::uuid(),
            'token_expires_at' => now()->addDays(30),
            'questionnaire_version' => 'v2_2026',
            'category' => VendorQuestionnaire::CATEGORY_CLOUD,
        ]);
    }

    /**
     * Bytes minimal PDF v1.4 yang valid untuk lulus finfo MIME detection
     * (FileUploadValidator::validate cek real MIME via finfo, bukan extension).
     */
    private function fakePdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
            ."xref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000056 00000 n\n0000000111 00000 n\n"
            ."trailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
    }
}
