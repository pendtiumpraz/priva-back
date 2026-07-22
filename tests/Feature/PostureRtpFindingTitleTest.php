<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\PostureFinding;
use App\Services\PostureFindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finding RTP overdue harus menyebut risiko sebenarnya. Item RTP tidak punya
 * key 'title'/'description' — yang ada 'risk_event', 'action', 'notes'.
 */
class PostureRtpFindingTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_finding_rtp_overdue_memakai_risk_event_dan_action(): void
    {
        $org = Organization::create(['name' => 'Tenant P', 'slug' => 'tenant-p-'.Str::random(6)]);

        Dpia::withoutGlobalScope('org')->create([
            'org_id' => $org->id,
            'registration_number' => 'DPIA-2026-001',
            'risk_level' => 'high',
            'status' => 'draft',
            'description' => 'Test DPIA',
            'mitigation_tracking' => [[
                'id' => (string) Str::uuid(),
                'risk_event' => 'Data disimpan melebihi masa retensi',
                'category' => 'Retensi',
                'treatment_type' => 'reduce',
                'action' => 'Terapkan job purge otomatis 30 hari',
                'status' => 'planned',
                'due_date' => now()->subDays(10)->toDateString(),
                'evidence_files' => [],
                'notes' => '',
            ]],
        ]);

        app(PostureFindingService::class)->materialize($org->id);

        $finding = PostureFinding::withoutGlobalScope('org')
            ->where('org_id', $org->id)
            ->where('source_pillar', 'rtp_hygiene')
            ->firstOrFail();

        $this->assertStringContainsString('Data disimpan melebihi masa retensi', $finding->title);
        $this->assertStringNotContainsString('mitigation item', $finding->title);
        $this->assertStringContainsString('Data disimpan melebihi masa retensi', (string) $finding->source_detail);
        $this->assertStringContainsString('Terapkan job purge otomatis 30 hari', (string) $finding->description);
    }

    public function test_finding_rtp_pakai_notes_kalau_action_kosong_dan_fallback_label(): void
    {
        $org = Organization::create(['name' => 'Tenant Q', 'slug' => 'tenant-q-'.Str::random(6)]);

        Dpia::withoutGlobalScope('org')->create([
            'org_id' => $org->id,
            'registration_number' => 'DPIA-2026-002',
            'risk_level' => 'high',
            'status' => 'draft',
            'description' => 'Test DPIA',
            'mitigation_tracking' => [[
                'id' => (string) Str::uuid(),
                'risk_event' => '',
                'action' => '',
                'notes' => 'Menunggu vendor menyediakan modul enkripsi',
                'status' => 'planned',
                'due_date' => now()->subDays(3)->toDateString(),
            ]],
        ]);

        app(PostureFindingService::class)->materialize($org->id);

        $finding = PostureFinding::withoutGlobalScope('org')
            ->where('org_id', $org->id)
            ->where('source_pillar', 'rtp_hygiene')
            ->firstOrFail();

        $this->assertStringContainsString('mitigation item', $finding->title);
        $this->assertStringContainsString('Menunggu vendor menyediakan modul enkripsi', (string) $finding->description);
    }
}
