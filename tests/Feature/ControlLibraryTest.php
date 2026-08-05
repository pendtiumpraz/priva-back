<?php

namespace Tests\Feature;

use App\Models\ControlLibraryItem;
use App\Models\Dpia;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pustaka kontrol: pengelolaan per tenant dan penerapannya ke DPIA.
 *
 * Dua sifat yang paling menentukan dan karena itu diuji paling ketat:
 * pustaka satu tenant tidak pernah terlihat tenant lain, dan menerapkan
 * kontrol dua kali tidak melipatgandakan item Risk Treatment Plan.
 */
class ControlLibraryTest extends TestCase
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
            'permissions' => ['dpia:read', 'dpia:write'],
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

    #[Test]
    public function katalog_bawaan_disemai_saat_pertama_dibuka(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->getJson('/api/dpia/control-library')->assertOk();

        $this->assertGreaterThanOrEqual(20, count($res->json('data')));
        $this->assertGreaterThan(0, $res->json('meta.seeded_now'));

        // Bauran jenis kontrol harus lengkap. Pustaka yang seluruhnya preventif
        // berarti kegagalan kontrol tidak akan pernah terdeteksi.
        $this->assertGreaterThan(0, $res->json('meta.mix.preventif'));
        $this->assertGreaterThan(0, $res->json('meta.mix.detektif'));
        $this->assertGreaterThan(0, $res->json('meta.mix.korektif'));

        // Tiap kontrol bawaan menunjuk dasar hukumnya — itulah yang membuatnya
        // berguna saat dihadapkan ke pemeriksa.
        foreach ($res->json('data') as $item) {
            $this->assertNotEmpty($item['reference'], "Kontrol {$item['title']} tanpa rujukan pasal.");
        }
    }

    #[Test]
    public function penyemaian_tidak_berulang_pada_pembukaan_berikutnya(): void
    {
        Sanctum::actingAs($this->user);

        $first = $this->getJson('/api/dpia/control-library')->assertOk();
        $second = $this->getJson('/api/dpia/control-library')->assertOk();

        $this->assertSame(0, $second->json('meta.seeded_now'));
        $this->assertCount(count($first->json('data')), $second->json('data'));
    }

    #[Test]
    public function kontrol_yang_sengaja_dihapus_tidak_muncul_kembali(): void
    {
        Sanctum::actingAs($this->user);

        $items = $this->getJson('/api/dpia/control-library')->json('data');
        $target = $items[0];

        $this->deleteJson("/api/dpia/control-library/{$target['id']}")->assertOk();

        // Membuka ulang tidak boleh menyemai ulang — kontrol yang dibuang
        // dengan sengaja akan muncul lagi tanpa diminta.
        $after = $this->getJson('/api/dpia/control-library')->assertOk();
        $this->assertSame(0, $after->json('meta.seeded_now'));
        $this->assertNotContains($target['id'], array_column($after->json('data'), 'id'));
    }

    #[Test]
    public function tenant_dapat_menambah_dan_menyunting_kontrolnya_sendiri(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/dpia/control-library');

        $created = $this->postJson('/api/dpia/control-library', [
            'code' => 'ORG-99',
            'category' => 'teknis',
            'title' => 'Tokenisasi nomor rekening',
            'control_type' => 'preventif',
            'reference' => 'Kebijakan internal',
            'default_effectiveness' => 3,
        ])->assertStatus(201)->json('data');

        $this->assertFalse($created['is_seeded']);

        $this->putJson("/api/dpia/control-library/{$created['id']}", [
            'title' => 'Tokenisasi nomor rekening dan kartu',
        ])->assertOk()->assertJsonPath('data.title', 'Tokenisasi nomor rekening dan kartu');
    }

    #[Test]
    public function reset_mengembalikan_katalog_bawaan(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/dpia/control-library');

        $this->postJson('/api/dpia/control-library', [
            'category' => 'fisik',
            'title' => 'Kontrol buatan sendiri',
            'control_type' => 'korektif',
        ])->assertStatus(201);

        $this->postJson('/api/dpia/control-library/reset')->assertOk();

        $after = $this->getJson('/api/dpia/control-library')->json('data');
        $this->assertNotContains('Kontrol buatan sendiri', array_column($after, 'title'));
        $this->assertGreaterThanOrEqual(20, count($after));
    }

    #[Test]
    public function kontrol_diterapkan_ke_dpia_sebagai_item_rtp(): void
    {
        Sanctum::actingAs($this->user);
        $items = $this->getJson('/api/dpia/control-library')->json('data');

        $dpia = Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-'.substr(uniqid(), -3),
            'title' => 'DPIA Pembukaan Rekening',
        ]);

        $chosen = array_slice(array_column($items, 'id'), 0, 3);

        $res = $this->postJson("/api/dpia/{$dpia->id}/apply-controls", [
            'control_ids' => $chosen,
            'risk_event' => 'Akses tidak sah ke data nasabah',
        ])->assertOk();

        $this->assertSame(3, $res->json('applied'));

        $rtp = $res->json('data');
        $this->assertCount(3, $rtp);
        $this->assertSame('Akses tidak sah ke data nasabah', $rtp[0]['risk_event']);
        $this->assertSame('control_library', $rtp[0]['source']);
        $this->assertSame('planned', $rtp[0]['status']);
        // Bentuk item harus sama dengan yang dibuat manual, kalau tidak papan
        // pemantauan RTP memperlakukan keduanya berbeda tanpa alasan.
        $this->assertArrayHasKey('evidence_files', $rtp[0]);
        $this->assertArrayHasKey('residual_likelihood', $rtp[0]);
        $this->assertArrayHasKey('treatment_type', $rtp[0]);
    }

    #[Test]
    public function menerapkan_kontrol_yang_sama_dua_kali_tidak_melipatgandakan_rtp(): void
    {
        Sanctum::actingAs($this->user);
        $items = $this->getJson('/api/dpia/control-library')->json('data');

        $dpia = Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-'.substr(uniqid(), -3),
            'title' => 'DPIA Uji Duplikasi',
        ]);

        $chosen = array_slice(array_column($items, 'id'), 0, 2);

        $this->postJson("/api/dpia/{$dpia->id}/apply-controls", ['control_ids' => $chosen])
            ->assertOk()->assertJsonPath('applied', 2);

        $second = $this->postJson("/api/dpia/{$dpia->id}/apply-controls", ['control_ids' => $chosen])
            ->assertOk();

        $this->assertSame(0, $second->json('applied'));
        $this->assertSame(2, $second->json('skipped'));
        $this->assertCount(2, $second->json('data'));
    }

    #[Test]
    public function pustaka_satu_tenant_tidak_terlihat_tenant_lain(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/dpia/control-library');
        $this->postJson('/api/dpia/control-library', [
            'category' => 'teknis',
            'title' => 'Rahasia Bank Uji',
            'control_type' => 'preventif',
        ])->assertStatus(201);

        $otherOrg = Organization::create(['name' => 'Bank Lain', 'slug' => 'lain-'.uniqid()]);
        $otherRole = TenantRole::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO',
            'permissions' => ['dpia:read', 'dpia:write'],
        ]);
        $otherUser = User::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO Lain',
            'email' => 'lain'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $otherRole->id,
        ]);

        Sanctum::actingAs($otherUser);
        $titles = array_column($this->getJson('/api/dpia/control-library')->json('data'), 'title');

        $this->assertNotContains('Rahasia Bank Uji', $titles);
        // Tenant kedua tetap memperoleh katalog bawaannya sendiri.
        $this->assertGreaterThanOrEqual(20, count($titles));
    }

    #[Test]
    public function tanpa_izin_dpia_pustaka_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'permissions' => ['ropa:read'],
        ]);
        $outsider = User::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'email' => 'no'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson('/api/dpia/control-library')->assertStatus(403);
        $this->postJson('/api/dpia/control-library', ['category' => 'teknis', 'title' => 'X', 'control_type' => 'preventif'])
            ->assertStatus(403);
    }

    #[Test]
    public function kategori_dan_jenis_di_luar_daftar_ditolak(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/dpia/control-library', [
            'category' => 'kategori-ngawur',
            'title' => 'X',
            'control_type' => 'preventif',
        ])->assertStatus(422);

        $this->postJson('/api/dpia/control-library', [
            'category' => 'teknis',
            'title' => 'X',
            'control_type' => 'jenis-ngawur',
        ])->assertStatus(422);

        $this->assertSame(0, ControlLibraryItem::withoutGlobalScope('org')->where('title', 'X')->count());
    }
}
