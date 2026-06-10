<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\QuestionLibrarySegment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bank Pertanyaan = single management surface — copy-on-write fork untuk
 * template platform.
 *
 * Verifikasi:
 *   - Edit pertanyaan template platform → fork otomatis (source=forked) yang
 *     men-shadow template di GET /tprm/libraries; template asli tidak berubah.
 *   - Edit kedua (masih memegang id template) di-redirect ke fork existing
 *     via pemetaan question_code — tidak bikin fork kedua.
 *   - Reset ke Default: fork di-soft-delete, template muncul kembali, dan
 *     pertanyaan fork TETAP ada di DB supaya asesmen in-flight yang
 *     ber-library_id fork tetap bisa dirender + di-skor.
 */
class TprmLibraryCowForkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private QuestionLibrary $template;

    private QuestionLibrarySegment $segment;

    private VendorQuestionnaire $templateQuestion;

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

        // Template platform (org_id NULL, locked) + 1 segment + 1 question —
        // miniatur dari QuestionLibraryBackfillSeeder.
        $this->template = QuestionLibrary::create([
            'id' => (string) Str::uuid(),
            'org_id' => null,
            'name' => 'Kepatuhan PDP UU 27/2022 — Pihak Ketiga',
            'slug' => 'pdp_compliance_v2_2026',
            'category' => 'pdp_compliance',
            'version' => 'v2_2026',
            'source' => QuestionLibrary::SOURCE_SEEDED,
            'is_active' => true,
            'is_locked' => true,
            'tags' => ['pdp', 'default'],
        ]);

        $this->segment = QuestionLibrarySegment::create([
            'id' => (string) Str::uuid(),
            'library_id' => $this->template->id,
            'name' => 'Tata Kelola',
            'code' => 'GOV',
            'order_index' => 0,
            'weight_pct' => 25,
        ]);

        $this->templateQuestion = VendorQuestionnaire::create([
            'org_id' => null,
            'parent_id' => null,
            'library_id' => $this->template->id,
            'library_segment_id' => $this->segment->id,
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

        $this->template->refreshCounters();
        $this->segment->refreshCounter();
    }

    public function test_editing_platform_template_question_forks_and_shadows(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->patchJson(
            "/api/tprm/libraries/{$this->template->id}/questions/{$this->templateQuestion->id}",
            ['question_text' => 'Teks ubahan tenant', 'weight' => 9],
        );

        $response->assertStatus(200)
            ->assertJsonPath('forked', true)
            ->assertJsonPath('data.question_text', 'Teks ubahan tenant')
            ->assertJsonPath('data.weight', 9);

        $forkId = $response->json('library.id');
        $this->assertNotNull($forkId);
        $this->assertNotSame($this->template->id, $forkId);

        // Fork = library milik org, source=forked, shadow template.
        $this->assertDatabaseHas('question_libraries', [
            'id' => $forkId,
            'org_id' => $this->org->id,
            'source' => QuestionLibrary::SOURCE_FORKED,
            'cloned_from_library_id' => $this->template->id,
        ]);

        // Template platform asli TIDAK berubah.
        $this->templateQuestion->refresh();
        $this->assertSame('Apakah pihak ketiga memiliki Kebijakan PDP?', $this->templateQuestion->question_text);

        // Index: org melihat fork, template asli ter-shadow.
        $list = $this->getJson('/api/tprm/libraries')->assertStatus(200)->json('data');
        $ids = collect($list)->pluck('id');
        $this->assertTrue($ids->contains($forkId), 'Fork harus muncul di list.');
        $this->assertFalse($ids->contains($this->template->id), 'Template ter-shadow oleh fork.');

        $forkRow = collect($list)->firstWhere('id', $forkId);
        $this->assertTrue($forkRow['is_fork']);

        // Edit KEDUA masih memegang id template (tab lama) → redirect ke fork
        // existing via question_code mapping, bukan bikin fork kedua.
        $second = $this->patchJson(
            "/api/tprm/libraries/{$this->template->id}/questions/{$this->templateQuestion->id}",
            ['question_text' => 'Teks ubahan kedua'],
        );
        $second->assertStatus(200)->assertJsonPath('library.id', $forkId);

        $forkCount = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $this->org->id)
            ->where('source', QuestionLibrary::SOURCE_FORKED)
            ->count();
        $this->assertSame(1, $forkCount, 'Edit berulang tidak boleh membuat fork kedua.');
    }

    public function test_reset_to_default_soft_deletes_fork_and_keeps_inflight_questions(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat fork via edit pertama.
        $forkId = $this->patchJson(
            "/api/tprm/libraries/{$this->template->id}/questions/{$this->templateQuestion->id}",
            ['question_text' => 'Teks ubahan tenant'],
        )->assertStatus(200)->json('library.id');

        // Asesmen in-flight yang memakai fork.
        $vendor = Vendor::create([
            'org_id' => $this->org->id,
            'name' => 'PT Vendor Uji',
            'category' => 'pdp_compliance',
        ]);
        VendorAssessment::create([
            'vendor_id' => $vendor->id,
            'org_id' => $this->org->id,
            'library_id' => $forkId,
            'status' => 'sent',
            'questionnaire_version' => 'v2_2026',
            'answers' => [],
        ]);

        $reset = $this->postJson("/api/tprm/libraries/{$forkId}/reset-to-default");
        $reset->assertStatus(200)
            ->assertJsonPath('in_flight_assessments', 1)
            ->assertJsonPath('data.id', $this->template->id);

        // Fork soft-deleted → template kembali muncul di list.
        $this->assertSoftDeleted('question_libraries', ['id' => $forkId]);
        $ids = collect($this->getJson('/api/tprm/libraries')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->template->id), 'Template platform muncul kembali setelah reset.');
        $this->assertFalse($ids->contains($forkId));

        // Pertanyaan fork TETAP ada (asesmen in-flight render/skor by library_id).
        $this->assertDatabaseHas('vendor_questionnaires', [
            'library_id' => $forkId,
            'org_id' => $this->org->id,
        ]);
    }

    public function test_fork_questions_do_not_leak_into_legacy_effective_set(): void
    {
        Sanctum::actingAs($this->admin);

        $before = VendorQuestionnaire::effectiveForOrg($this->org->id)->count();

        // Fork template → 1 copy pertanyaan milik org dengan library_id terisi.
        $this->patchJson(
            "/api/tprm/libraries/{$this->template->id}/questions/{$this->templateQuestion->id}",
            ['question_text' => 'Teks ubahan tenant'],
        )->assertStatus(200);

        $after = VendorQuestionnaire::effectiveForOrg($this->org->id)->count();
        $this->assertSame(
            $before,
            $after,
            'Pertanyaan fork (library_id terisi) tidak boleh bocor ke set efektif legacy.'
        );
    }
}
