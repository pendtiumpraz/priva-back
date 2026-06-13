<?php

namespace Tests\Feature\PolicyGenerator;

use Laravel\Sanctum\Sanctum;

/**
 * PDF download + branded HTML embed snippet for generated policies (Fase 3).
 */
class PolicyRenderTest extends PolicyGeneratorTestCase
{
    public function test_download_pdf_returns_a_pdf_document(): void
    {
        $org = $this->makeOrg(['name' => 'PT Contoh Sejahtera', 'website' => 'https://contoh.id']);
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $resp = $this->get("/api/policy-generations/{$policy->id}/download.pdf");

        $resp->assertStatus(200);
        $resp->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->streamedContent());

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'policy_generator',
            'action' => 'download.pdf',
            'record_id' => $policy->id,
        ]);
    }

    public function test_embed_html_returns_branded_self_contained_fragment(): void
    {
        $org = $this->makeOrg(['name' => 'PT Contoh Sejahtera', 'website' => 'https://contoh.id']);
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $resp = $this->get("/api/policy-generations/{$policy->id}/embed.html");

        $resp->assertStatus(200);
        $this->assertStringContainsString('text/html', $resp->headers->get('content-type'));

        $html = $resp->getContent();
        // Scoped wrapper so it can be embedded into a third-party page safely.
        $this->assertStringContainsString('pg-policy', $html);
        // White-label: tenant name appears (header), website (footer).
        $this->assertStringContainsString('PT Contoh Sejahtera', $html);
        $this->assertStringContainsString('contoh.id', $html);
        // Content + mandatory disclaimer rendered.
        $this->assertStringContainsString('Isi kebijakan privasi.', $html);
        $this->assertStringContainsStringIgnoringCase('nasihat hukum', $html);
    }

    public function test_pdf_of_other_org_policy_is_not_found(): void
    {
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $policyA = $this->makePolicy($orgA, $userA);

        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);

        Sanctum::actingAs($userB);
        $this->get("/api/policy-generations/{$policyA->id}/download.pdf")->assertStatus(404);
        $this->get("/api/policy-generations/{$policyA->id}/embed.html")->assertStatus(404);
    }

    public function test_embed_unauthenticated_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        $this->getJson("/api/policy-generations/{$policy->id}/embed.html")->assertStatus(401);
    }
}
