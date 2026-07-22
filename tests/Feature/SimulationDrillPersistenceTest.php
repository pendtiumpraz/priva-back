<?php

namespace Tests\Feature;

use App\Models\BreachSimulation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fire Drill (Simulation) — per-step response persistence.
 *
 * Tabletop narrative answers and walkthrough checklist state used to be thrown
 * away by the browser (only the aggregate counters reached the server). These
 * tests lock in that `POST /api/simulations/complete` now stores the per-step
 * detail, validates it, and derives the critical-item aggregates server-side —
 * while records saved BEFORE this change (no `responses` / `checklist` keys)
 * keep loading unchanged.
 */
class SimulationDrillPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'PT Drill Uji',
            'slug' => 'drill-uji-'.Str::random(6),
        ]);

        // role admin lolos legacy fallback di CheckPermission (write allowed).
        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);
    }

    public function test_tabletop_run_persists_narrative_responses_per_step(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'ransomware',
            'scenario_title' => 'Tabletop Ransomware',
            'mode' => 'tabletop',
            'overall_score' => 100,
            'duration_seconds' => 600,
            'score_breakdown' => [
                'mode' => 'tabletop',
                'rating' => 'Excellent',
                'score_percent' => 100,
                'total_score' => 2,
                'max_score' => 2,
            ],
            'findings' => [
                'steps_answered' => 2,
                'total_steps' => 2,
                'responses' => [
                    ['step_id' => 'T1', 'phase' => 'Deteksi', 'response' => 'Isolasi server terinfeksi.', 'time_spent' => 240],
                    ['step_id' => 'T2', 'phase' => 'Notifikasi', 'response' => 'Lapor KOMDIGI 3x24 jam.', 'time_spent' => 360],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $sim = BreachSimulation::where('org_id', $this->org->id)->firstOrFail();

        $this->assertSame($this->org->id, $sim->org_id);
        $this->assertCount(2, $sim->findings['responses']);
        $this->assertSame('T1', $sim->findings['responses'][0]['step_id']);
        $this->assertSame('Deteksi', $sim->findings['responses'][0]['phase']);
        $this->assertSame('Isolasi server terinfeksi.', $sim->findings['responses'][0]['response']);
        $this->assertSame(240, $sim->findings['responses'][0]['time_spent']);
        $this->assertSame('Lapor KOMDIGI 3x24 jam.', $sim->findings['responses'][1]['response']);

        // score_breakdown must survive non-empty for tabletop runs.
        $this->assertSame('Excellent', $sim->score_breakdown['rating']);
        $this->assertSame('tabletop', $sim->score_breakdown['mode']);
    }

    public function test_tabletop_responses_are_validated_and_reshaped(): void
    {
        Sanctum::actingAs($this->admin);

        // Missing step_id => rejected.
        $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'ransomware',
            'scenario_title' => 'Tabletop Ransomware',
            'mode' => 'tabletop',
            'overall_score' => 50,
            'findings' => ['responses' => [['response' => 'tanpa step id']]],
        ])->assertStatus(422)->assertJsonValidationErrors('findings.responses.0.step_id');

        // Unknown keys are dropped — only the whitelisted shape is stored.
        $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'ransomware',
            'scenario_title' => 'Tabletop Ransomware',
            'mode' => 'tabletop',
            'overall_score' => 50,
            'findings' => ['responses' => [['step_id' => 'T1', 'response' => 'ok', 'evil' => '<script>']]],
        ])->assertStatus(201);

        $sim = BreachSimulation::where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame(
            ['step_id', 'phase', 'response', 'time_spent'],
            array_keys($sim->findings['responses'][0])
        );
    }

    public function test_walkthrough_run_persists_checklist_and_derives_critical_aggregates(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'breach_response',
            'scenario_title' => 'Walkthrough SOP',
            'mode' => 'walkthrough',
            'overall_score' => 75,
            'duration_seconds' => 300,
            'score_breakdown' => [
                'mode' => 'walkthrough',
                'rating' => 'Good',
                'score_percent' => 75,
            ],
            'findings' => [
                'items_checked' => 3,
                'total_items' => 4,
                // Client-sent aggregates are deliberately WRONG here — the
                // server must recompute them from the item list.
                'critical_checked' => 99,
                'total_critical' => 99,
                'checklist' => [
                    ['step_id' => 'W1', 'phase' => 'Deteksi', 'item' => 'Isolasi sistem', 'checked' => true, 'critical' => true],
                    ['step_id' => 'W1', 'phase' => 'Deteksi', 'item' => 'Catat waktu deteksi', 'checked' => true, 'critical' => false],
                    ['step_id' => 'W2', 'phase' => 'Notifikasi', 'item' => 'Lapor KOMDIGI', 'checked' => false, 'critical' => true],
                    ['step_id' => 'W2', 'phase' => 'Notifikasi', 'item' => 'Siapkan siaran pers', 'checked' => true, 'critical' => false],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $sim = BreachSimulation::where('org_id', $this->org->id)->firstOrFail();

        $this->assertCount(4, $sim->findings['checklist']);
        $this->assertSame(2, $sim->findings['total_critical']);
        $this->assertSame(1, $sim->findings['critical_checked']);

        $missed = collect($sim->findings['checklist'])->firstWhere('item', 'Lapor KOMDIGI');
        $this->assertTrue($missed['critical']);
        $this->assertFalse($missed['checked']);
        $this->assertSame('W2', $missed['step_id']);
        $this->assertSame('Notifikasi', $missed['phase']);

        $this->assertSame('walkthrough', $sim->score_breakdown['mode']);
        $this->assertNotEmpty($sim->score_breakdown);
    }

    public function test_walkthrough_checklist_requires_item_and_checked(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'breach_response',
            'scenario_title' => 'Walkthrough SOP',
            'mode' => 'walkthrough',
            'overall_score' => 10,
            'findings' => ['checklist' => [['step_id' => 'W1', 'critical' => true]]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['findings.checklist.0.item', 'findings.checklist.0.checked']);
    }

    public function test_legacy_records_without_new_keys_still_load(): void
    {
        Sanctum::actingAs($this->admin);

        // Shape produced by the OLD frontend: aggregates only, no per-step data,
        // and an empty score_breakdown.
        $legacy = BreachSimulation::create([
            'org_id' => $this->org->id,
            'scenario_title' => 'Walkthrough Lama',
            'scenario_type' => 'breach_response',
            'status' => 'completed',
            'overall_score' => 60,
            'score_breakdown' => [],
            'findings' => [
                'participant_name' => 'Budi',
                'mode' => 'walkthrough',
                'items_checked' => 6,
                'total_items' => 10,
                'duration_seconds' => 120,
            ],
            'created_by' => $this->admin->id,
        ]);

        $show = $this->getJson("/api/simulations/{$legacy->id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.findings.items_checked', 6)
            ->assertJsonPath('data.findings.total_items', 10);

        // No fabricated keys — the detail view must not invent "0 dari 0".
        $findings = $show->json('data.findings');
        $this->assertArrayNotHasKey('checklist', $findings);
        $this->assertArrayNotHasKey('responses', $findings);
        $this->assertArrayNotHasKey('critical_checked', $findings);
        $this->assertArrayNotHasKey('total_critical', $findings);

        $this->getJson('/api/simulations')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $legacy->id);
    }

    public function test_live_mode_payload_without_new_keys_is_untouched(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'live_ransomware',
            'scenario_title' => 'Live Drill',
            'mode' => 'live',
            'overall_score' => 80,
            'duration_seconds' => 90,
            'score_breakdown' => ['rating' => 'Good', 'phase_scores' => [['phase' => 'detection', 'score' => 8, 'max' => 10, 'time' => 30]]],
            'findings' => ['records_leaked' => 100, 'max_records' => 5000, 'severity' => 'HIGH'],
        ])->assertStatus(201);

        $sim = BreachSimulation::where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame(100, $sim->findings['records_leaked']);
        $this->assertArrayNotHasKey('checklist', $sim->findings);
        $this->assertArrayNotHasKey('total_critical', $sim->findings);
        $this->assertCount(1, $sim->score_breakdown['phase_scores']);
    }

    public function test_complete_is_scoped_to_the_callers_org(): void
    {
        $otherOrg = Organization::create(['name' => 'PT Lain', 'slug' => 'lain-'.Str::random(6)]);
        $otherUser = User::factory()->create(['org_id' => $otherOrg->id, 'role' => 'admin']);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/simulations/complete', [
            'scenario_type' => 'ransomware',
            'scenario_title' => 'Tabletop Org A',
            'mode' => 'tabletop',
            'overall_score' => 100,
            'findings' => ['responses' => [['step_id' => 'T1', 'response' => 'rahasia org A']]],
        ])->assertStatus(201);

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/simulations')->assertStatus(200)->assertJsonCount(0, 'data');
    }
}
