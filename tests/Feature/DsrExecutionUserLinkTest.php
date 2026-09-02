<?php

namespace Tests\Feature;

use App\Models\DsrExecution;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Eksekusi DSR menautkan pelaksana ke user platform (mirror alur verifikasi),
 * bukan sekadar string email bebas.
 */
class DsrExecutionUserLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create(['org_id' => $this->org->id, 'name' => 'Admin', 'permissions' => ['*']]);
        $this->user = User::create([
            'org_id' => $this->org->id, 'name' => 'Admin Uji', 'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'tenant_role_id' => $role->id,
        ]);
    }

    private function makeExecution(): array
    {
        $dsr = DsrRequest::create([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -3),
            'request_type' => 'access',
            'requester_name' => 'Pemohon',
            'requester_email' => 'pemohon@uji.id',
            'status' => 'in_progress',
            'deadline_at' => now()->addHours(72),
        ]);

        $exec = DsrExecution::create([
            'dsr_request_id' => $dsr->id,
            'information_system_id' => (string) Str::uuid(),
            'request_type' => 'access',
            'status' => 'pending',
        ]);

        return [$dsr, $exec];
    }

    #[Test]
    public function eksekusi_menautkan_user_dan_menyalin_email(): void
    {
        [$dsr, $exec] = $this->makeExecution();
        $executor = User::create([
            'org_id' => $this->org->id, 'name' => 'Petugas Eksekusi',
            'email' => 'petugas'.uniqid().'@uji.id', 'password' => bcrypt('secret123'), 'role' => 'maker',
        ]);

        Sanctum::actingAs($this->user);

        $this->patchJson("/api/dsr/{$dsr->id}/executions/{$exec->id}", [
            'status' => 'executed',
            'executed_by_user_id' => $executor->id,
            'executed_by_email' => 'diabaikan@uji.id',
        ])->assertOk();

        $exec->refresh();
        $this->assertSame($executor->id, $exec->executed_by_user_id);
        // Email disalin dari user, bukan dari string yang dikirim.
        $this->assertSame($executor->email, $exec->executed_by_email);
    }

    #[Test]
    public function user_id_lintas_org_diabaikan_default_ke_aktor(): void
    {
        [$dsr, $exec] = $this->makeExecution();
        $otherOrg = Organization::create(['name' => 'Org Lain', 'slug' => 'olain-'.uniqid()]);
        $foreign = User::create([
            'org_id' => $otherOrg->id, 'name' => 'Luar', 'email' => 'luar'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'maker',
        ]);

        Sanctum::actingAs($this->user);

        $this->patchJson("/api/dsr/{$dsr->id}/executions/{$exec->id}", [
            'status' => 'executed',
            'executed_by_user_id' => $foreign->id,
        ])->assertOk();

        $exec->refresh();
        // user lintas-org ditolak → default ke aktor (user yang login).
        $this->assertSame($this->user->id, $exec->executed_by_user_id);
        $this->assertSame($this->user->email, $exec->executed_by_email);
    }
}
