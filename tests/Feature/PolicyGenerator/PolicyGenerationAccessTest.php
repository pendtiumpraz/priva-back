<?php

namespace Tests\Feature\PolicyGenerator;

use Laravel\Sanctum\Sanctum;

/**
 * Listing, retrieval, DOCX download, soft-delete, and — critically —
 * cross-tenant isolation for generated policies.
 */
class PolicyGenerationAccessTest extends PolicyGeneratorTestCase
{
    public function test_index_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/policy-generations')->assertStatus(401);
    }

    public function test_index_lists_only_own_org_policies(): void
    {
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $policyA = $this->makePolicy($orgA, $userA, ['title' => 'Policy A']);

        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);
        $this->makePolicy($orgB, $userB, ['title' => 'Policy B']);

        Sanctum::actingAs($userA);
        $resp = $this->getJson('/api/policy-generations')->assertStatus(200);

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($policyA->id, $ids);
        $this->assertCount(1, $ids, 'Index must not leak other tenants policies');
    }

    public function test_show_returns_own_policy(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $this->getJson("/api/policy-generations/{$policy->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $policy->id)
            ->assertJsonPath('data.audience', 'customer');
    }

    public function test_show_other_org_policy_is_not_found(): void
    {
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $policyA = $this->makePolicy($orgA, $userA);

        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);

        Sanctum::actingAs($userB);
        $this->getJson("/api/policy-generations/{$policyA->id}")->assertStatus(404);
    }

    public function test_download_docx_returns_a_word_document(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $resp = $this->get("/api/policy-generations/{$policy->id}/download.docx");

        $resp->assertStatus(200);
        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $resp->assertDownload('kebijakan_privasi.docx');

        // Download is audited.
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'policy_generator',
            'action' => 'download.docx',
            'record_id' => $policy->id,
        ]);
    }

    public function test_downloaded_docx_always_carries_the_legal_disclaimer(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        // Simulate a stored policy whose sections somehow LACK the footer node.
        $policy = $this->makePolicy($org, $user, [
            'ai_output' => [
                'title' => 'Kebijakan Privasi',
                'metadata' => [],
                'sections' => [['type' => 'paragraph', 'text' => 'Isi tanpa footer disclaimer.']],
            ],
        ]);

        Sanctum::actingAs($user);
        $resp = $this->get("/api/policy-generations/{$policy->id}/download.docx")->assertStatus(200);

        // Unzip the DOCX and assert the disclaimer made it into the document body.
        $tmp = tempnam(sys_get_temp_dir(), 'pg_dl_').'.docx';
        file_put_contents($tmp, $resp->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsStringIgnoringCase('nasihat hukum', (string) $xml);
    }

    public function test_destroy_other_org_policy_is_not_found(): void
    {
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $policyA = $this->makePolicy($orgA, $userA);

        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);

        Sanctum::actingAs($userB);
        $this->deleteJson("/api/policy-generations/{$policyA->id}")->assertStatus(404);

        // Org A's policy must NOT have been soft-deleted by org B's request.
        $this->assertDatabaseHas('generated_policies', ['id' => $policyA->id, 'deleted_at' => null]);
    }

    public function test_download_other_org_policy_is_not_found(): void
    {
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $policyA = $this->makePolicy($orgA, $userA);

        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);

        Sanctum::actingAs($userB);
        $this->get("/api/policy-generations/{$policyA->id}/download.docx")->assertStatus(404);
    }

    public function test_destroy_soft_deletes_own_policy(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $policy = $this->makePolicy($org, $user);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/policy-generations/{$policy->id}")->assertStatus(200);

        $this->assertSoftDeleted('generated_policies', ['id' => $policy->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'policy_generator', 'action' => 'delete', 'record_id' => $policy->id]);
    }
}
