<?php

namespace Tests\Feature\PolicyGenerator;

use App\Models\ConsentCollectionPoint;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

/**
 * POST /api/ai-features/policy/autofill — deterministic, source-tagged pre-fill
 * of wizard inputs from the tenant's existing modules.
 */
class PolicyAutofillTest extends PolicyGeneratorTestCase
{
    private const ENDPOINT = '/api/ai-features/policy/autofill';

    private function seedModuleData(Organization $org, User $user): void
    {
        Ropa::forceCreate([
            'org_id' => $org->id,
            'registration_number' => 'ROPA-'.uniqid(),
            'processing_activity' => 'Pemrosesan data pelanggan',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'purpose' => 'Penyediaan layanan dan dukungan pelanggan',
            'legal_basis' => 'kontrak',
            'data_categories' => ['Nama lengkap', 'Email', 'Nomor telepon'],
            'retention_period' => '5 tahun setelah penutupan akun',
            'wizard_data' => ['pengiriman_data' => ['transfer_luar' => 'yes', 'negara_tujuan' => 'Singapura', 'safeguards' => 'SCC']],
        ]);

        Vendor::forceCreate([
            'org_id' => $org->id,
            'name' => 'PT Penyedia Pembayaran',
            'services_provided' => ['Payment gateway'],
            'data_shared' => ['Nama', 'Email'],
            'country' => 'Indonesia',
            'type' => 'processor',
            'category' => 'payment',
        ]);

        ConsentCollectionPoint::forceCreate([
            'org_id' => $org->id,
            'collection_id' => 'CCP-'.uniqid(),
            'name' => 'Cookie Banner Website',
            'created_by' => $user->id,
            'kind' => 'cookie_banner',
            'redirect_url' => 'https://contoh.id/pusat-preferensi',
        ]);

        TiaAssessment::forceCreate([
            'org_id' => $org->id,
            'title' => 'TIA Singapura',
            'destination_country' => 'Singapura',
            'transfer_basis' => 'SCC',
        ]);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_basic_tier_is_denied(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'basic');
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT)->assertStatus(403)->assertJsonPath('upgrade_required', true);
    }

    public function test_prefills_from_tenant_modules_with_source_tags(): void
    {
        $org = $this->makeOrg(['name' => 'PT Contoh Sejahtera', 'email' => 'info@contoh.id', 'has_dpo' => true]);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedModuleData($org, $user);
        Sanctum::actingAs($user);

        $resp = $this->postJson(self::ENDPOINT, ['audience' => 'customer'])->assertStatus(200);

        // Identity from Organization.
        $this->assertSame('PT Contoh Sejahtera', $resp->json('data.wizard_inputs.company_name'));

        // Element 3: data categories from RoPA.
        $this->assertContains('Nama lengkap', $resp->json('data.wizard_inputs.data_categories'));
        $this->assertSame('RoPA', $resp->json('data.sources.data_categories.source'));

        // Element 4/5/6 from RoPA.
        $this->assertContains('Penyediaan layanan dan dukungan pelanggan', $resp->json('data.wizard_inputs.purposes'));
        // legal_basis slug is translated to a Pasal 20 label.
        $this->assertTrue(collect($resp->json('data.wizard_inputs.legal_basis'))->contains(fn ($l) => str_contains($l, 'kontrak') && str_contains($l, 'Pasal 20')));
        $this->assertContains('5 tahun setelah penutupan akun', $resp->json('data.wizard_inputs.retention'));

        // Element 7: third parties from TPRM (Vendor).
        $vendorNames = collect($resp->json('data.wizard_inputs.third_parties'))->pluck('name')->all();
        $this->assertContains('PT Penyedia Pembayaran', $vendorNames);
        $this->assertStringContainsString('TPRM', $resp->json('data.sources.third_parties.source'));

        // Element 13: cross-border from TIA + RoPA.
        $this->assertContains('Singapura', $resp->json('data.wizard_inputs.cross_border'));

        // Element 9: consent withdrawal from Consent Management.
        $points = collect($resp->json('data.wizard_inputs.consent_withdrawal'))->pluck('point')->all();
        $this->assertContains('Cookie Banner Website', $points);

        // Element 8: DSR rights (static) present + source-tagged.
        $this->assertNotEmpty($resp->json('data.wizard_inputs.data_subject_rights.types'));
        $this->assertSame('static', $resp->json('data.sources.data_subject_rights.confidence'));

        // Coverage estimate reflects how much was filled.
        $this->assertGreaterThan(5, $resp->json('data.coverage_estimate.filled'));

        // Auto-Fill is deterministic (no LLM) → it must NOT charge a credit.
        $org->refresh();
        $this->assertEquals(100, $org->ai_credits_remaining);
        $this->assertDatabaseMissing('ai_credit_logs', ['org_id' => $org->id]);
    }

    public function test_unknown_audience_falls_back_to_customer(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        Sanctum::actingAs($user);

        $resp = $this->postJson(self::ENDPOINT, ['audience' => 'not_a_real_audience'])->assertStatus(200);

        // Customer fallback → cookie + child-data fields are present (no audience exclusions).
        $inputs = $resp->json('data.wizard_inputs');
        $this->assertArrayHasKey('cookie_policy', $inputs);
        $this->assertArrayHasKey('child_data', $inputs);
    }

    public function test_autofill_does_not_leak_other_org_module_data(): void
    {
        // Org A has rich module data.
        $orgA = $this->makeOrg();
        $userA = $this->makeUser($orgA);
        $this->seedModuleData($orgA, $userA);

        // Org B has NONE — its autofill must come back empty, never org A's.
        $orgB = $this->makeOrg();
        $userB = $this->makeUser($orgB);
        $this->giveAiLicense($orgB, 'ai');
        Sanctum::actingAs($userB);

        $resp = $this->postJson(self::ENDPOINT, ['audience' => 'customer'])->assertStatus(200);

        $this->assertEmpty($resp->json('data.wizard_inputs.data_categories'));
        $this->assertEmpty($resp->json('data.wizard_inputs.third_parties'));
        $this->assertEmpty($resp->json('data.wizard_inputs.cross_border'));
        $this->assertEmpty($resp->json('data.wizard_inputs.consent_withdrawal'));
    }

    public function test_employee_audience_omits_cookie_and_child_data_fields(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        Sanctum::actingAs($user);

        $resp = $this->postJson(self::ENDPOINT, ['audience' => 'employee'])->assertStatus(200);

        $inputs = $resp->json('data.wizard_inputs');
        $this->assertArrayNotHasKey('cookie_policy', $inputs);
        $this->assertArrayNotHasKey('child_data', $inputs);
    }

    public function test_autofilled_inputs_can_drive_generation(): void
    {
        $org = $this->makeOrg(['name' => 'PT Contoh']);
        $user = $this->makeUser($org);
        $this->giveAiLicense($org, 'ai');
        $this->seedModuleData($org, $user);
        $this->seedAiProvider($org);
        Sanctum::actingAs($user);

        $prefill = $this->postJson(self::ENDPOINT, ['audience' => 'customer'])->assertStatus(200)->json('data.wizard_inputs');

        // Feed the (reviewed) autofilled inputs straight into generation.
        $this->fakeAi($this->fullPolicyOutput());
        $resp = $this->postJson('/api/ai-features/policy/generate', [
            'document_type' => 'privacy_policy',
            'audience' => 'customer',
            'language' => 'id',
            'title' => 'Kebijakan Privasi PT Contoh',
            'wizard_inputs' => $prefill,
            'legal_acknowledgement' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('generated_policies', ['id' => $resp->json('policy_id'), 'org_id' => $org->id]);
    }
}
