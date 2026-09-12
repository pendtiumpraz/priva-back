<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\SecurityAlert;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Register insiden PLATFORM (UU PDP Pasal 46).
 *
 * Seluruh modul Breach mencatat insiden yang dialami TENANT. Tidak ada tempat
 * untuk mencatat insiden yang dialami platform ini sendiri — padahal terhadap
 * tenant kami berkedudukan sebagai Prosesor, sehingga satu insiden di sisi kami
 * menjadi kewajiban pemberitahuan bagi SETIAP Pengendali yang datanya kami
 * proses. Tanpa penyebaran ini, tenant tidak pernah tahu jam 3x24 mereka sudah
 * berjalan.
 *
 * Yang dijaga di sini:
 *   1. hanya root/platform staff yang boleh mencatat dan menyebarkan;
 *   2. penyebaran membuat baris breach MILIK TENANT dengan source='platform';
 *   3. tenant yang sudah tidak berjalan (archived/transferred) dilewati,
 *      sedangkan tenant `frozen` TETAP ikut — pembekuan hanya memblokir login,
 *      kewajiban pemberitahuannya tidak ikut beku;
 *   4. nomor insiden tetap unik lintas tenant (batasannya global — F-03);
 *   5. penyebaran kedua ditolak supaya catatan tenant tidak berganda.
 */
class InsidenPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $orgPlatform = Organization::factory()->create(['name' => 'Privasimu']);
        $this->root = User::factory()->create([
            'org_id' => $orgPlatform->id,
            'role' => 'root',
        ]);
        Sanctum::actingAs($this->root);
    }

    private function tenant(string $nama, string $lifecycle): Organization
    {
        return Organization::factory()->create([
            'name' => $nama,
            'lifecycle_status' => $lifecycle,
        ]);
    }

    /** @param array<string, mixed> $override */
    private function catat(array $override = []): string
    {
        $res = $this->postJson('/api/platform-incidents', array_merge([
            'title' => 'Akses tidak sah ke basis data bersama',
            'description' => 'Log audit menunjukkan akses dari IP tidak dikenal.',
            'severity' => 'high',
            'affected_scope' => 'all',
        ], $override))->assertStatus(201);

        return $res->json('data.id');
    }

    public function test_hanya_root_yang_boleh_mencatat_insiden_platform(): void
    {
        $org = $this->tenant('PT Tenant Biasa', 'active');
        $peran = TenantRole::create([
            'org_id' => $org->id,
            'name' => 'admin',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $org->id,
            'role' => 'admin',
            'tenant_role_id' => $peran->id,
        ]));

        $this->postJson('/api/platform-incidents', [
            'title' => 'Percobaan dari tenant',
            'severity' => 'low',
            'affected_scope' => 'all',
        ])->assertStatus(403);

        $this->getJson('/api/platform-incidents')->assertStatus(403);
    }

    public function test_penyebaran_membuat_breach_di_tiap_tenant_yang_berjalan(): void
    {
        $aktif = $this->tenant('PT Masih Jalan', 'active');
        $beku = $this->tenant('PT Dibekukan', 'frozen');
        $arsip = $this->tenant('PT Sudah Arsip', 'archived');
        $pindah = $this->tenant('PT Sudah Pindah', 'transferred');

        $id = $this->catat();
        $res = $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();

        $breaches = BreachIncident::withoutGlobalScope('org')->where('source', 'platform')->get();

        // Tenant yang berjalan menerima; yang sudah tidak berjalan tidak.
        $this->assertTrue($breaches->contains('org_id', $aktif->id));
        $this->assertTrue(
            $breaches->contains('org_id', $beku->id),
            'tenant beku hanya terblokir login — kewajiban pemberitahuannya tidak ikut beku',
        );
        $this->assertFalse($breaches->contains('org_id', $arsip->id));
        $this->assertFalse($breaches->contains('org_id', $pindah->id));

        $res->assertJsonPath('ringkasan.gagal', 0);
    }

    public function test_nomor_insiden_tetap_unik_lintas_tenant(): void
    {
        $this->tenant('PT Satu', 'active');
        $this->tenant('PT Dua', 'active');
        $this->tenant('PT Tiga', 'active');

        $id = $this->catat();
        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();

        $kode = BreachIncident::withoutGlobalScope('org')
            ->where('source', 'platform')->pluck('incident_code');

        $this->assertSame(
            $kode->count(),
            $kode->unique()->count(),
            'batasan unik incident_code bersifat global lintas tenant (F-03)',
        );
    }

    public function test_breach_tenant_tidak_merujuk_pengguna_root(): void
    {
        $this->tenant('PT Satu', 'active');

        $id = $this->catat();
        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();

        $breach = BreachIncident::withoutGlobalScope('org')->where('source', 'platform')->first();

        // created_by adalah foreign key ke users; pengguna root tidak ada di
        // database tenant terisolasi, jadi pelakunya dicatat di timeline_log.
        $this->assertNull($breach->created_by);
        $this->assertNotEmpty($breach->timeline_log);
        $this->assertSame('platform', $breach->timeline_log[0]['actor']);
    }

    public function test_lingkup_terpilih_hanya_menyentuh_tenant_yang_dipilih(): void
    {
        $dipilih = $this->tenant('PT Dipilih', 'active');
        $lain = $this->tenant('PT Tidak Dipilih', 'active');

        $id = $this->catat([
            'affected_scope' => 'selected',
            'affected_org_ids' => [$dipilih->id],
        ]);
        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();

        $breaches = BreachIncident::withoutGlobalScope('org')->where('source', 'platform')->get();

        $this->assertTrue($breaches->contains('org_id', $dipilih->id));
        $this->assertFalse($breaches->contains('org_id', $lain->id));
    }

    public function test_penyebaran_kedua_ditolak(): void
    {
        $this->tenant('PT Satu', 'active');
        $id = $this->catat();

        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();
        $sesudahPertama = BreachIncident::withoutGlobalScope('org')->where('source', 'platform')->count();

        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertStatus(422);

        // Dibandingkan terhadap jumlah sesudah penyebaran pertama, bukan angka
        // tetap: berapa organisasi yang ada di fixture bukan bagian dari yang
        // diuji di sini — yang diuji adalah tidak bertambahnya catatan.
        $this->assertGreaterThan(0, $sesudahPertama);
        $this->assertSame(
            $sesudahPertama,
            BreachIncident::withoutGlobalScope('org')->where('source', 'platform')->count(),
            'catatan insiden tenant tidak boleh berganda',
        );
    }

    public function test_insiden_terkunci_setelah_disebarkan(): void
    {
        $this->tenant('PT Satu', 'active');
        $id = $this->catat();

        $this->putJson("/api/platform-incidents/{$id}", ['title' => 'Judul baru'])->assertOk();
        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();
        $this->putJson("/api/platform-incidents/{$id}", ['title' => 'Diubah sesudahnya'])->assertStatus(422);
    }

    public function test_tenant_menerima_notifikasi_dan_jejak_audit_tercatat(): void
    {
        $org = $this->tenant('PT Satu', 'active');
        $peran = TenantRole::create([
            'org_id' => $org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        User::factory()->create([
            'org_id' => $org->id,
            'role' => 'dpo',
            'tenant_role_id' => $peran->id,
        ]);

        $id = $this->catat();
        $this->postJson("/api/platform-incidents/{$id}/sebarkan")->assertOk();

        $this->assertTrue(
            SecurityAlert::where('org_id', $org->id)->where('type', 'platform.incident')->exists(),
            'DPO tenant harus diberi tahu — penyebaran tanpa pemberitahuan tidak ada gunanya',
        );

        foreach (['created', 'fanned_out'] as $aksi) {
            $this->assertTrue(
                AuditLog::where('module', 'platform_incident')->where('action', $aksi)->exists(),
                "jejak audit '{$aksi}' harus ada",
            );
        }
    }
}
