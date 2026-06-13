<?php

namespace Tests\Feature\PolicyGenerator;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Version-diff staleness (AI Agent tier): a generated policy carries a fingerprint
 * of its source-module data at generation time; staleness is detected when the
 * tenant's current source data no longer matches — i.e. RoPA changed → re-review.
 */
class PolicyVersionDiffTest extends PolicyGeneratorTestCase
{
    private function seedRopa(Organization $org, User $user, array $categories): void
    {
        Ropa::forceCreate([
            'org_id' => $org->id,
            'registration_number' => 'ROPA-'.uniqid(),
            'processing_activity' => 'Pemrosesan',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'purpose' => 'Layanan',
            'legal_basis' => 'kontrak',
            'data_categories' => $categories,
        ]);
    }

    private function generatePolicy(Organization $org, User $user): string
    {
        $this->seedAiProvider($org);
        $this->fakeAi($this->fullPolicyOutput());
        Sanctum::actingAs($user);

        return $this->postJson('/api/ai-features/policy/generate', [
            'document_type' => 'privacy_policy',
            'audience' => 'customer',
            'language' => 'id',
            'title' => 'Kebijakan Privasi',
            'wizard_inputs' => ['company_name' => 'PT Contoh'],
            'legal_acknowledgement' => true,
        ])->assertStatus(200)->json('policy_id');
    }

    public function test_ai_agent_staleness_detects_source_change(): void
    {
        $org = $this->makeOrg(['ai_credits_remaining' => 100]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai_agent');
        $this->seedRopa($org, $user, ['Nama', 'Email']);

        $id = $this->generatePolicy($org, $user);

        // Right after generation, the policy is in sync with its source data.
        $this->getJson("/api/policy-generations/{$id}/staleness")
            ->assertStatus(200)
            ->assertJsonPath('data.has_baseline', true)
            ->assertJsonPath('data.stale', false);

        // A source module (RoPA) changes → policy becomes stale → re-review.
        $this->seedRopa($org, $user, ['Nomor Telepon', 'Alamat', 'Data Biometrik']);

        $this->getJson("/api/policy-generations/{$id}/staleness")
            ->assertStatus(200)
            ->assertJsonPath('data.stale', true);
    }

    public function test_staleness_requires_ai_agent_tier(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai'); // AI tier, not AI Agent
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $this->getJson("/api/policy-generations/{$policy->id}/staleness")
            ->assertStatus(403)
            ->assertJsonPath('upgrade_required', true);
    }
}
