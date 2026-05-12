<?php

namespace Tests\Feature;

use App\Models\GapAssessment;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Sprint G.8 + G.9 — AiDocumentAnalyzer end-to-end via GapAssessment endpoint.
 *
 * Verifies credit-gating (402 when exhausted), per-call deduction, sha256 cache
 * reuse, dan persist hasil ke `gap_assessments.ai_analyses[question_id]`.
 *
 * AiService di-mock di container supaya tidak ada panggilan HTTP nyata ke
 * provider AI saat test. CreditService tetap real (static facade-style),
 * supaya integration test memang menyentuh logika decrement organization
 * `ai_credits_remaining`.
 */
class AiDocumentAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private GapAssessment $assessment;
    private string $questionId = 'TK-FR-01'; // ada di question bank UU PDP default.
    private string $attachmentRelative;
    private string $attachmentAbsolute;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::create([
            'name' => 'Tenant Uji AI',
            'slug' => 'tenant-ai-'.Str::random(6),
            'ai_credits_monthly' => 10.0,
            'ai_credits_remaining' => 5.0,
            'ai_credits_purchased' => 0.0,
            'ai_credits_reset_at' => now()->addMonth(),
        ]);

        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);

        // Lampiran fisik — controller pakai resolveAttachmentPath() yang
        // mencoba storage_path('app/public/<rel>') lalu storage_path('app/<rel>').
        // Kita tulis ke storage/app/<rel> agar matched candidate kedua.
        //
        // Pakai .txt supaya AiDocumentAnalyzer::extractText() lewat jalur
        // file_get_contents() (no Smalot/PhpWord parser) — kita fokus uji
        // alur credit + cache + persist hasil, bukan parser dokumen.
        $this->attachmentRelative = 'tests/gap/evidence-'.Str::random(8).'.txt';
        $this->attachmentAbsolute = storage_path('app/'.$this->attachmentRelative);
        File::ensureDirectoryExists(dirname($this->attachmentAbsolute));
        File::put($this->attachmentAbsolute, "Dokumen bukti kepatuhan kerangka PDP organisasi.\n");

        // Assessment dengan attachments map dan answer existing untuk question_id.
        $this->assessment = GapAssessment::create([
            'org_id' => $this->org->id,
            'regulation_code' => 'uupdp',
            'version' => 'v1',
            'overall_score' => 0,
            'compliance_level' => 'low',
            'progress' => 0,
            'answers' => [$this->questionId => 'yes'],
            'attachments' => [
                $this->questionId => [
                    ['path' => $this->attachmentRelative, 'name' => 'bukti.pdf'],
                ],
            ],
            'ai_analyses' => [],
            'recommendations' => [],
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->attachmentAbsolute) && File::exists($this->attachmentAbsolute)) {
            File::delete($this->attachmentAbsolute);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_analyze_endpoint_requires_credit(): void
    {
        $this->org->update(['ai_credits_remaining' => 0, 'ai_credits_purchased' => 0]);
        $this->bindAiServiceMock(); // mock tetap dipasang biar dependency container resolve

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/gap/{$this->assessment->id}/analyze-evidence", [
            'question_id' => $this->questionId,
            'attachment_path' => $this->attachmentRelative,
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('credits_exhausted', true);
    }

    public function test_analyze_consumes_one_credit_on_success(): void
    {
        $this->bindAiServiceMock(); // 1 call diharapkan

        Sanctum::actingAs($this->admin);

        $before = (float) $this->org->fresh()->ai_credits_remaining;

        $response = $this->postJson("/api/gap/{$this->assessment->id}/analyze-evidence", [
            'question_id' => $this->questionId,
            'attachment_path' => $this->attachmentRelative,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'comply');

        $after = (float) $this->org->fresh()->ai_credits_remaining;
        $this->assertSame(
            $before - 1.0,
            $after,
            'AiDocumentAnalyzer harus deduct tepat 1 kredit pada panggilan sukses pertama.',
        );
    }

    public function test_analyze_caches_result_by_hash(): void
    {
        // `once()` — hanya boleh dipanggil sekali; panggilan kedua harus cache hit
        // tanpa menyentuh AiService lagi.
        $this->bindAiServiceMock(timesAsk: 1);

        Sanctum::actingAs($this->admin);

        $payload = [
            'question_id' => $this->questionId,
            'attachment_path' => $this->attachmentRelative,
        ];

        $first = $this->postJson("/api/gap/{$this->assessment->id}/analyze-evidence", $payload);
        $first->assertStatus(200);

        $creditsAfterFirst = (float) $this->org->fresh()->ai_credits_remaining;

        $second = $this->postJson("/api/gap/{$this->assessment->id}/analyze-evidence", $payload);
        $second->assertStatus(200);

        $creditsAfterSecond = (float) $this->org->fresh()->ai_credits_remaining;

        $this->assertSame(
            $creditsAfterFirst,
            $creditsAfterSecond,
            'Cache hit tidak boleh deduct credit kedua kalinya.',
        );
    }

    public function test_analyze_writes_to_assessment_ai_analyses(): void
    {
        $this->bindAiServiceMock();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/gap/{$this->assessment->id}/analyze-evidence", [
            'question_id' => $this->questionId,
            'attachment_path' => $this->attachmentRelative,
        ])->assertStatus(200);

        $fresh = $this->assessment->fresh();
        $this->assertIsArray($fresh->ai_analyses);
        $this->assertArrayHasKey($this->questionId, $fresh->ai_analyses);
        $this->assertSame('comply', $fresh->ai_analyses[$this->questionId]['status']);
        $this->assertSame($this->attachmentRelative, $fresh->ai_analyses[$this->questionId]['attachment_path']);
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Bind a mocked AiService into the container. Returns response yang
     * lulus parser AiDocumentAnalyzer (status comply + analysis non-empty).
     *
     * `times` defaults to 0+ (Mockery::times any). Set explicit kalau test
     * harus enforce jumlah pemanggilan (mis. cache hit scenarios).
     */
    private function bindAiServiceMock(?int $timesAsk = null): void
    {
        $mock = Mockery::mock(AiService::class);
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('setLocale')->andReturnSelf();

        $askExpectation = $mock->shouldReceive('ask')->andReturn([
            'status' => 'comply',
            'analysis' => 'Dokumen telah memenuhi pertanyaan kepatuhan.',
            'cited_passages' => [],
            'confidence' => 0.9,
        ]);

        if ($timesAsk !== null) {
            $askExpectation->times($timesAsk);
        }

        $this->app->instance(AiService::class, $mock);
    }

}
