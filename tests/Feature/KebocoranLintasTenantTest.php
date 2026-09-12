<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\FeatureRequest;
use App\Models\KnowledgeBaseSection;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kebocoran lintas-tenant dan gerbang otorisasi yang tertinggal.
 *
 * Aplikasi ini dijual untuk membantu organisasi mematuhi UU PDP. Kebocoran data
 * pribadi antar-tenant di dalamnya bukan sekadar bug — itu pelanggaran Pasal 35
 * dan Pasal 39 UU PDP oleh aplikasinya sendiri. Karena itu setiap lubang di
 * bawah dikunci uji, bukan hanya ditambal.
 *
 * Yang dijaga:
 *   1. Knowledge Base hanya menampilkan milik tenant sendiri + baris bersama.
 *   2. Papan usulan PUBLIK tidak memaparkan identitas siapa pun.
 *   3. Usulan tenant lain tidak terbaca, tidak terubah, tidak terhapus.
 *   4. Setelan tingkat platform tidak terjangkau peran tenant.
 */
class KebocoranLintasTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->orgB = Organization::factory()->create(['name' => 'PT Tetangga']);
    }

    private function penggunaTenant(Organization $org, array $permissions = ['*']): User
    {
        $role = TenantRole::create([
            'org_id' => $org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => $permissions,
        ]);

        // `role` kolom platform: admin TENANT juga bernilai 'admin' — inilah yang
        // dulu disalahartikan sebagai "staf platform".
        return User::factory()->create([
            'org_id' => $org->id,
            'role' => 'admin',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function stafPlatform(): User
    {
        return User::factory()->create(['org_id' => $this->orgA->id, 'role' => 'superadmin']);
    }

    // ---------- 1. Knowledge Base ----------

    private function kbSection(?string $orgId, string $title): KnowledgeBaseSection
    {
        return KnowledgeBaseSection::create([
            'org_id' => $orgId,
            // module_key, title, content, dan keywords semuanya NOT NULL tanpa
            // default (lihat migrasi 2026_03_26_100003). module_key dibuat unik
            // per baris: kendala `kb_org_module_unique` adalah (org_id,
            // module_key), jadi memakai nilai yang sama untuk semua baris hanya
            // selamat karena org_id-nya kebetulan berbeda.
            'module_key' => 'kb-'.md5($orgId.$title),
            'title' => $title,
            'content' => 'Isi rahasia untuk '.$title,
            'keywords' => 'uji,kebocoran',
            'category' => 'general',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_kb_tenant_lain_tidak_pernah_terbaca(): void
    {
        $this->kbSection($this->orgA->id, 'Milik A');
        $this->kbSection($this->orgB->id, 'Milik B');
        $this->kbSection(null, 'Panduan Bersama');

        Sanctum::actingAs($this->penggunaTenant($this->orgA));

        $res = $this->getJson('/api/ai/knowledge-base')->assertOk();
        $judul = collect($res->json('sections'))->pluck('title')->all();

        $this->assertContains('Milik A', $judul);
        $this->assertContains('Panduan Bersama', $judul, 'baris platform tetap boleh terlihat');
        $this->assertNotContains('Milik B', $judul, 'KB tenant lain tidak boleh bocor');

        // Isinya juga tidak boleh ikut di teks gabungan.
        $this->assertStringNotContainsString('Milik B', (string) $res->json('data'));
    }

    public function test_staf_platform_tetap_melihat_seluruh_kb(): void
    {
        $this->kbSection($this->orgA->id, 'Milik A');
        $this->kbSection($this->orgB->id, 'Milik B');

        Sanctum::actingAs($this->stafPlatform());

        $judul = collect($this->getJson('/api/ai/knowledge-base')->assertOk()->json('sections'))
            ->pluck('title')->all();

        $this->assertContains('Milik A', $judul);
        $this->assertContains('Milik B', $judul);
    }

    // ---------- 2 & 3. Feature requests ----------

    private function usulan(Organization $org, User $user, string $judul): FeatureRequest
    {
        return FeatureRequest::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'title' => $judul,
            'description' => 'Deskripsi '.$judul,
            'category' => 'module',
            'priority' => 'medium',
            'status' => 'submitted',
        ]);
    }

    public function test_papan_usulan_publik_tidak_memaparkan_identitas(): void
    {
        $userA = $this->penggunaTenant($this->orgA);
        $this->usulan($this->orgA, $userA, 'Usulan A');

        // Tanpa autentikasi sama sekali.
        $res = $this->getJson('/api/public/feature-requests')->assertOk();

        $mentah = json_encode($res->json());
        $this->assertStringContainsString('Usulan A', $mentah);
        // Tidak boleh ada jejak identitas pengusul.
        $this->assertStringNotContainsString($userA->email, $mentah);
        $this->assertStringNotContainsString($userA->name, $mentah);
        $this->assertArrayNotHasKey('user', $res->json('data.0'));
        $this->assertArrayNotHasKey('guest_email', $res->json('data.0'));
        $this->assertArrayNotHasKey('org_id', $res->json('data.0'));
    }

    public function test_admin_tenant_tidak_melihat_usulan_tenant_lain(): void
    {
        $userA = $this->penggunaTenant($this->orgA);
        $userB = $this->penggunaTenant($this->orgB);
        $this->usulan($this->orgA, $userA, 'Usulan A');
        $this->usulan($this->orgB, $userB, 'Usulan B');

        Sanctum::actingAs($userA);

        $judul = collect($this->getJson('/api/feature-requests')->assertOk()->json('data'))
            ->pluck('title')->all();

        $this->assertContains('Usulan A', $judul);
        $this->assertNotContains('Usulan B', $judul);
    }

    public function test_usulan_tenant_lain_tidak_terbaca_dan_tidak_terhapus(): void
    {
        $userA = $this->penggunaTenant($this->orgA);
        $userB = $this->penggunaTenant($this->orgB);
        $punyaB = $this->usulan($this->orgB, $userB, 'Usulan B');

        Sanctum::actingAs($userA);

        $this->getJson("/api/feature-requests/{$punyaB->id}")->assertStatus(404);
        $this->deleteJson("/api/feature-requests/{$punyaB->id}")->assertStatus(404);

        $this->assertNotNull(
            FeatureRequest::find($punyaB->id),
            'usulan tenant lain tidak boleh ikut terhapus',
        );
    }

    public function test_perubahan_status_dan_hapus_permanen_hanya_staf_platform(): void
    {
        $userA = $this->penggunaTenant($this->orgA);
        $punyaA = $this->usulan($this->orgA, $userA, 'Usulan A');

        Sanctum::actingAs($userA);

        // Status & catatan adalah keputusan platform, bukan tenant — walau
        // usulannya milik tenant itu sendiri.
        $this->putJson("/api/feature-requests/{$punyaA->id}", ['status' => 'completed'])
            ->assertStatus(403);

        $punyaA->delete();
        $this->deleteJson("/api/feature-requests/{$punyaA->id}/force")->assertStatus(403);

        $this->assertNotNull(FeatureRequest::withTrashed()->find($punyaA->id));
    }

    // ---------- 4. Persetujuan AI tidak boleh datang dari pemohon ----------

    public function test_flag_approved_dari_payload_klien_tidak_dipercaya(): void
    {
        // Antrian dipalsukan: dengan QUEUE_CONNECTION=sync, dispatch akan
        // MENJALANKAN ProcessAiJob seketika dan benar-benar memanggil eksekutor
        // AI. Yang ingin diamati di sini cuma isi payload yang tersimpan.
        Queue::fake();

        Sanctum::actingAs($this->penggunaTenant($this->orgA));

        $this->postJson('/api/ai/jobs', [
            'type' => 'analyzer',
            'label' => 'Uji persetujuan',
            'payload' => ['tool' => 'list_ropa', 'args' => [], 'approved' => true],
        ])->assertStatus(202);

        $job = AiJob::withoutGlobalScope('org')->first();

        $this->assertNotNull($job);
        $this->assertArrayNotHasKey(
            'approved',
            $job->payload,
            'persetujuan menjalankan tool harus keputusan server, bukan klaim pemohon',
        );
        // Sisa payload tetap utuh — yang dibuang hanya flag persetujuannya.
        $this->assertSame('list_ropa', $job->payload['tool']);
    }

    // ---------- 5. Setelan platform ----------

    public function test_peran_tenant_tidak_menjangkau_setelan_platform(): void
    {
        // Peran tenant yang punya settings:write — persis kasus yang dulu lolos.
        Sanctum::actingAs($this->penggunaTenant($this->orgA, ['settings:read', 'settings:write']));

        $this->getJson('/api/platform-admin/settings')->assertStatus(403);
        $this->getJson('/api/platform-admin/pentest-reports')->assertStatus(403);
    }

    public function test_staf_platform_tetap_bisa_membuka_setelan_platform(): void
    {
        Sanctum::actingAs($this->stafPlatform());

        $this->getJson('/api/platform-admin/settings')->assertSuccessful();
    }
}
