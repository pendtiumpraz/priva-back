<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;
use Database\Seeders\NexusTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji NexusTenantSeeder sebelum dijalankan di basis data sungguhan.
 *
 * Yang paling penting di sini adalah sifat idempoten: seeder ini ditujukan
 * untuk basis data produksi yang sudah berisi tenant lain, jadi menjalankannya
 * dua kali tidak boleh menggandakan apa pun.
 */
class NexusTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->seed(NexusTenantSeeder::class);
    }

    public function test_membuat_tenant_beserta_admin(): void
    {
        $this->runSeeder();

        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->first();

        $this->assertNotNull($org);
        $this->assertSame('Privasimu Nexus', $org->name);
        $this->assertTrue((bool) $org->has_dpo);

        $admin = User::withoutGlobalScopes()->where('org_id', $org->id)->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
    }

    public function test_mengisi_ropa_dpia_dan_item_rtp(): void
    {
        $this->runSeeder();

        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->firstOrFail();

        $ropa = Ropa::withoutGlobalScopes()->where('org_id', $org->id)->get();
        $dpia = Dpia::withoutGlobalScopes()->where('org_id', $org->id)->get();

        $this->assertCount(13, $ropa);
        $this->assertCount(4, $dpia);

        // Setiap DPIA harus menempel ke RoPA sumbernya, bukan menggantung.
        foreach ($dpia as $d) {
            $this->assertNotNull($d->ropa_id, "DPIA {$d->registration_number} tidak tertaut RoPA");
            $this->assertTrue(
                $ropa->contains('id', $d->ropa_id),
                "DPIA {$d->registration_number} menunjuk RoPA di luar tenant ini"
            );
        }

        // Item RTP hidup di dalam mitigation_tracking — itulah yang dibaca
        // RiskTreatmentPlanController.
        $items = $dpia->flatMap(fn ($d) => $d->mitigation_tracking ?? []);
        $this->assertGreaterThanOrEqual(12, $items->count());

        foreach ($items as $item) {
            foreach (['id', 'risk_event', 'action', 'treatment_type', 'priority', 'status', 'owner_user_id'] as $field) {
                $this->assertArrayHasKey($field, $item, "Item RTP kehilangan field {$field}");
            }
            $this->assertContains($item['treatment_type'], ['avoid', 'reduce', 'transfer', 'accept']);
            $this->assertContains($item['priority'], ['critical', 'high', 'medium', 'low']);
            $this->assertContains($item['status'], ['planned', 'in_progress', 'implemented', 'verified', 'overdue', 'on_hold', 'cancelled']);
        }
    }

    public function test_empat_ropa_berisiko_tinggi_punya_dpia(): void
    {
        $this->runSeeder();

        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->firstOrFail();

        $high = Ropa::withoutGlobalScopes()
            ->where('org_id', $org->id)
            ->where('risk_level', 'high')
            ->pluck('id');

        $covered = Dpia::withoutGlobalScopes()->where('org_id', $org->id)->pluck('ropa_id');

        $this->assertCount(4, $high);
        foreach ($high as $id) {
            $this->assertTrue($covered->contains($id), 'Ada RoPA risiko tinggi tanpa DPIA');
        }
    }

    public function test_dpia_mengisi_dua_puluh_satu_kategori_risiko(): void
    {
        $this->runSeeder();

        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->firstOrFail();

        foreach (Dpia::withoutGlobalScopes()->where('org_id', $org->id)->get() as $d) {
            $kategori = $d->wizard_data['potensi_risiko'] ?? [];
            $this->assertCount(21, $kategori, "DPIA {$d->registration_number} tidak lengkap 21 kategori");

            foreach ($kategori as $nama => $isi) {
                $this->assertContains($isi['status'], ['sudah', 'sebagian', 'belum', 'tidak_berlaku'], "Status kategori {$nama} tidak valid");
            }
        }
    }

    public function test_dijalankan_dua_kali_tidak_menggandakan(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertCount(1, Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->get());

        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->firstOrFail();

        $this->assertSame(13, Ropa::withoutGlobalScopes()->where('org_id', $org->id)->count());
        $this->assertSame(4, Dpia::withoutGlobalScopes()->where('org_id', $org->id)->count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('org_id', $org->id)->count());
    }

    public function test_tidak_menyentuh_tenant_lain(): void
    {
        $lain = Organization::create([
            'id' => (string) Str::uuid(),
            'name' => 'PT Tenant Lain',
            'slug' => 'tenant-lain',
        ]);

        Ropa::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'org_id' => $lain->id,
            'registration_number' => 'ROPA-LAIN-001',
            'processing_activity' => 'Aktivitas milik tenant lain',
        ]);

        $this->runSeeder();

        // Data tenant lain harus utuh — seeder hanya menyentuh org miliknya.
        $this->assertSame(1, Ropa::withoutGlobalScopes()->where('org_id', $lain->id)->count());
        $this->assertDatabaseHas('ropas', ['registration_number' => 'ROPA-LAIN-001']);
    }
}
