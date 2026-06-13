<?php

namespace Tests\Feature\PolicyGenerator;

use App\Models\AuditLog;
use App\Models\GeneratedPolicy;
use Laravel\Sanctum\Sanctum;

/**
 * POST /api/ai-features/policy/generate — gating, validation, legal-safety,
 * persistence, audit + credit metering.
 */
class PolicyGenerateEndpointTest extends PolicyGeneratorTestCase
{
    private const ENDPOINT = '/api/ai-features/policy/generate';

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'document_type' => 'privacy_policy',
            'audience' => 'customer',
            'language' => 'id',
            'title' => 'Kebijakan Privasi PT Contoh',
            'wizard_inputs' => [
                'company_name' => 'PT Contoh',
                'data_categories' => 'nama, email, nomor telepon',
                'purposes' => 'penyediaan layanan',
            ],
            'legal_acknowledgement' => true,
        ], $override);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload())->assertStatus(401);
    }

    public function test_basic_tier_is_denied_with_upgrade_required(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'basic');
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(403)
            ->assertJsonPath('upgrade_required', true);
    }

    public function test_exhausted_credits_return_402(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 0, 'ai_credits_purchased' => 0]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(402)
            ->assertJsonPath('credits_exhausted', true);
    }

    public function test_missing_legal_acknowledgement_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload(['legal_acknowledgement' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('legal_acknowledgement');
    }

    public function test_happy_path_generates_persists_and_meters(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 100]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        $this->fakeAi($this->fullPolicyOutput());
        Sanctum::actingAs($user);

        $resp = $this->postJson(self::ENDPOINT, $this->validPayload());

        $resp->assertStatus(200)
            ->assertJsonPath('type', 'policy_generator')
            ->assertJsonPath('saved', true)
            ->assertJsonStructure(['data' => ['sections'], 'ai_result_id', 'policy_id', 'credits_used', 'credits_remaining', 'coverage']);

        $policyId = $resp->json('policy_id');

        // Persisted, org-scoped, draft.
        $this->assertDatabaseHas('generated_policies', [
            'id' => $policyId,
            'org_id' => $org->id,
            'created_by' => $user->id,
            'audience' => 'customer',
            'language' => 'id',
            'status' => 'draft',
        ]);

        // AI result + audit + credit log.
        $this->assertDatabaseHas('ai_results', ['org_id' => $org->id, 'feature_type' => 'policy_generator', 'record_id' => $policyId]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'policy_generator', 'action' => 'generated', 'record_id' => $policyId]);
        $this->assertDatabaseHas('ai_credit_logs', ['org_id' => $org->id, 'action_type' => 'policy_generator', 'status' => 'success']);

        // Credits actually deducted (cost > 0).
        $org->refresh();
        $this->assertLessThan(100, $org->ai_credits_remaining);

        // 15-element coverage surfaced + legal footer present in output.
        $this->assertSame(15, $resp->json('coverage.covered_count'));
        $this->assertStringContainsStringIgnoringCase('bukan nasihat hukum', json_encode($resp->json('data.sections'), JSON_UNESCAPED_UNICODE));

        $policy = GeneratedPolicy::withoutGlobalScope('org')->findOrFail($policyId);

        // Provenance recorded for THIS tenant (not the global/null provider).
        $this->assertStringStartsWith('test-', (string) $policy->ai_provider);
        $this->assertSame('test-model', $policy->ai_model);

        // Footer persisted in the stored column (renders in DOCX), not just the response.
        $persisted = json_encode($policy->ai_output['sections'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsStringIgnoringCase('bukan nasihat hukum', $persisted);

        // Legal-safety audit row carries the acknowledgement + disclaimer version.
        $audit = AuditLog::where('module', 'policy_generator')
            ->where('action', 'generated')
            ->where('record_id', $policyId)
            ->firstOrFail();
        $changes = is_array($audit->changes) ? $audit->changes : json_decode($audit->changes, true);
        $this->assertTrue($changes['legal_acknowledged']);
        $this->assertSame(15, $changes['coverage_covered']);
        $this->assertNotEmpty($changes['disclaimer_version']);
        $this->assertNotEmpty($changes['clause_sources']);
    }

    public function test_generates_for_each_audience_template(): void
    {
        foreach (['employee', 'job_applicant', 'external'] as $audience) {
            $org = $this->makeOrg(['ai_credits_remaining' => 100]);
            $user = $this->makeUser($org);
            $this->giveAiLicense($org, 'ai');
            $this->seedAiProvider($org);
            $this->fakeAi($this->fullPolicyOutput());
            Sanctum::actingAs($user);

            $resp = $this->postJson(self::ENDPOINT, $this->validPayload(['audience' => $audience]))
                ->assertStatus(200);

            $this->assertDatabaseHas('generated_policies', [
                'id' => $resp->json('policy_id'),
                'org_id' => $org->id,
                'audience' => $audience,
                'status' => 'draft',
            ]);
        }
    }

    public function test_ai_failure_returns_502_and_does_not_charge_credits(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 100]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        // Malformed AI output: valid JSON but no `sections` array.
        $this->fakeAi(['title' => 'Tanpa Sections', 'note' => 'malformed output']);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload(['title' => 'Kebijakan Gagal']))->assertStatus(502);

        $org->refresh();
        $this->assertEquals(100, $org->ai_credits_remaining);
        $this->assertDatabaseHas('ai_credit_logs', ['org_id' => $org->id, 'action_type' => 'policy_generator', 'status' => 'failed']);
        $this->assertDatabaseMissing('ai_credit_logs', ['org_id' => $org->id, 'status' => 'success']);
        $this->assertDatabaseMissing('generated_policies', ['org_id' => $org->id]);
    }

    public function test_invalid_audience_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload(['audience' => 'partner']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('audience');
    }

    public function test_invalid_language_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload(['language' => 'fr']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('language');
    }

    public function test_audience_and_document_type_default_when_omitted(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 100]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedAiProvider($org);
        $this->fakeAi($this->fullPolicyOutput());
        Sanctum::actingAs($user);

        $payload = $this->validPayload();
        unset($payload['audience'], $payload['document_type']);

        $resp = $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        $this->assertDatabaseHas('generated_policies', [
            'id' => $resp->json('policy_id'),
            'audience' => 'customer',
            'document_type' => 'privacy_policy',
            'status' => 'draft',
        ]);
    }

    public function test_unavailable_provider_returns_503_without_charging(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 100]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        // No provider seeded → AiService::isAvailable() is false.
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->validPayload())->assertStatus(503);

        $org->refresh();
        $this->assertEquals(100, $org->ai_credits_remaining);
        $this->assertDatabaseMissing('ai_credit_logs', ['org_id' => $org->id, 'status' => 'success']);
    }
}
