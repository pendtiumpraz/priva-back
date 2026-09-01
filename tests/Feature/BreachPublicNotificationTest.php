<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notifikasi publik atas Kegagalan Pelindungan Data Pribadi — PP 33/2026
 * Pasal 115.
 */
class BreachPublicNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO',
            'permissions' => ['breach:read', 'breach:write'],
        ]);
        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'email' => 'dpo'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function breach(): BreachIncident
    {
        return BreachIncident::create([
            'org_id' => $this->org->id,
            'incident_code' => 'BRC-2026-'.substr(uniqid(), -4),
            'title' => 'Kebocoran basis data pelanggan',
            'description' => 'Akses tidak sah ke sebagian data pelanggan.',
            'severity' => 'high',
            'status' => 'detected',
            'affected_data_types' => ['nama', 'email'],
            'affected_subjects_count' => 1200,
        ]);
    }

    #[Test]
    public function field_notifikasi_publik_tersimpan(): void
    {
        $breach = $this->breach();
        $breach->update([
            'public_notification_required' => true,
            'public_notification_grounds' => ['pelayanan_publik', 'kepentingan_masyarakat'],
            'notified_public_at' => now(),
        ]);

        $fresh = $breach->fresh();
        $this->assertTrue($fresh->public_notification_required);
        $this->assertContains('pelayanan_publik', $fresh->public_notification_grounds);
        $this->assertNotNull($fresh->notified_public_at);
    }

    #[Test]
    public function pdf_pengumuman_publik_dapat_diunduh(): void
    {
        Sanctum::actingAs($this->user);
        $breach = $this->breach();
        $breach->update(['public_notification_required' => true, 'public_notification_grounds' => ['pelayanan_publik']]);

        $res = $this->get("/api/breach/{$breach->id}/pdf/public-notice");
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower($res->headers->get('content-type') ?? ''));
    }

    #[Test]
    public function tanpa_izin_breach_ditolak(): void
    {
        $role = TenantRole::create(['org_id' => $this->org->id, 'name' => 'X', 'permissions' => ['ropa:read']]);
        $u = User::create([
            'org_id' => $this->org->id, 'name' => 'X', 'email' => 'x'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'staff', 'tenant_role_id' => $role->id,
        ]);
        $breach = $this->breach();

        Sanctum::actingAs($u);
        $this->get("/api/breach/{$breach->id}/pdf/public-notice")->assertStatus(403);
    }
}
