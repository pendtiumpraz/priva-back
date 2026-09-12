<?php

namespace Tests\Feature;

use App\Models\EmbedToken;
use App\Models\Organization;
use App\Models\RecordShareLink;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dua cara membuka RoPA/DPIA ke luar, dengan janji yang berbeda.
 *
 * Embed (iframe, daftar terkurasi):
 *   1. hanya kolom dalam daftar putih yang keluar — `wizard_data` tidak pernah,
 *      bahkan bila diminta saat penerbitan;
 *   2. tidak ada jalur tulis sama sekali;
 *   3. tautan organisasi lain tidak pernah menampilkan baris kita.
 *
 * Tautan lembaga (satu dokumen, dijaga kata sandi):
 *   4. sebelum kata sandi benar, tidak ada isi yang terungkap;
 *   5. membuka halaman TIDAK memakan jatah kunjungan — hanya kata sandi yang
 *      benar yang menghitung (pratinjau tautan di aplikasi pesan jangan sampai
 *      menghabiskan jatah sebelum petugasnya membuka);
 *   6. kata sandi salah juga tidak memakan jatah;
 *   7. setelah jatah habis, tautan mencabut diri sendiri.
 */
class EmbedDanTautanLembagaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['ropa:read', 'ropa:write', 'dpia:read', 'dpia:write'],
        ]);
        $this->user = User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]);
        Sanctum::actingAs($this->user);
    }

    private function ropa(array $override = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'processing_activity' => 'Pembukaan Rekening',
            'purpose' => 'Onboarding nasabah',
            'legal_basis' => 'kontrak',
            'risk_level' => 'high',
            'status' => 'approved',
            'assignees' => [$this->user->id],
            'review_notes' => 'catatan telaah internal',
            'wizard_data' => ['dpo_team' => ['dpo_email' => 'dpo@contoh.co.id']],
        ], $override));
    }

    // ---------- Embed ----------

    public function test_embed_hanya_mengeluarkan_kolom_daftar_putih(): void
    {
        $this->ropa();

        // Minta kolom terlarang secara eksplisit — harus diiris di server.
        $buat = $this->postJson('/api/ropa/tautan-embed', [
            'label' => 'Register publik situs utama',
            'fields' => ['registration_number', 'processing_activity', 'wizard_data', 'assignees'],
        ])->assertCreated();

        $token = $buat->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertNotContains('wizard_data', $buat->json('data.fields'));
        $this->assertNotContains('assignees', $buat->json('data.fields'));

        $baris = $this->getJson("/api/embed-publik/{$token}/data")->assertOk()->json('data.0');

        $this->assertArrayHasKey('registration_number', $baris);
        $this->assertArrayNotHasKey('wizard_data', $baris);
        $this->assertArrayNotHasKey('assignees', $baris);
        $this->assertArrayNotHasKey('review_notes', $baris);
    }

    public function test_embed_menolak_penulisan_dan_token_mati(): void
    {
        $this->ropa();
        $token = $this->postJson('/api/ropa/tautan-embed', ['label' => 'Register'])
            ->assertCreated()->json('data.token');

        // Tidak ada jalur tulis, apa pun rutenya.
        $this->postJson("/api/embed-publik/{$token}/data")->assertStatus(405);

        $this->getJson('/api/embed-publik/token-ngawur/data')->assertStatus(404);

        EmbedToken::withoutGlobalScope('org')->where('token', $token)->update(['revoked_at' => now()]);
        $this->getJson("/api/embed-publik/{$token}/data")->assertStatus(410);
    }

    public function test_embed_tidak_pernah_menampilkan_baris_organisasi_lain(): void
    {
        $this->ropa(['processing_activity' => 'Milik Kami']);
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $this->ropa(['org_id' => $lain->id, 'processing_activity' => 'Milik Tetangga']);

        $token = $this->postJson('/api/ropa/tautan-embed', ['label' => 'Register'])
            ->assertCreated()->json('data.token');

        $res = $this->getJson("/api/embed-publik/{$token}/data")->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.processing_activity', 'Milik Kami');
    }

    public function test_embed_rotasi_dan_pencabutan_mematikan_tautan_lama(): void
    {
        $this->ropa();
        $buat = $this->postJson('/api/ropa/tautan-embed', ['label' => 'Register'])->assertCreated();
        $token = $buat->json('data.token');
        $id = $buat->json('data.id');

        $rotasi = $this->postJson("/api/ropa/tautan-embed/{$id}/rotasi")->assertOk();
        $tokenBaru = $rotasi->json('data.token');

        $this->assertNotSame($token, $tokenBaru);
        $this->getJson("/api/embed-publik/{$token}/data")->assertStatus(404);
        $this->getJson("/api/embed-publik/{$tokenBaru}/data")->assertOk();

        $this->postJson("/api/ropa/tautan-embed/{$id}/cabut")->assertOk();
        $this->getJson("/api/embed-publik/{$tokenBaru}/data")->assertStatus(410);
    }

    // ---------- Tautan lembaga ----------

    private function terbitkan(array $override = []): array
    {
        $ropa = $this->ropa();
        $res = $this->postJson('/api/ropa/tautan-lembaga', array_merge([
            'record_id' => $ropa->id,
            'recipient_label' => 'Kementerian Komdigi',
            'max_views' => 2,
        ], $override))->assertCreated();

        return [$res->json('data.url'), $res->json('data.password'), $res->json('data.id'), $ropa];
    }

    public function test_tautan_lembaga_tidak_membocorkan_isi_sebelum_kata_sandi(): void
    {
        [$url, , , $ropa] = $this->terbitkan();
        $token = basename((string) $url);

        $info = $this->getJson("/api/berbagi-publik/{$token}")->assertOk()->json('data');

        $this->assertTrue($info['requires_password']);
        $this->assertSame('ropa', $info['module']);
        // Tidak ada isi dokumen, nama organisasi, maupun penerimanya.
        $this->assertArrayNotHasKey('processing_activity', $info);
        $this->assertArrayNotHasKey('organization', $info);
        $this->assertArrayNotHasKey('recipient_label', $info);
        $this->assertStringNotContainsString($ropa->registration_number, json_encode($info));
    }

    public function test_membuka_halaman_dan_sandi_salah_tidak_memakan_jatah(): void
    {
        [$url, , $id] = $this->terbitkan();
        $token = basename((string) $url);

        $this->getJson("/api/berbagi-publik/{$token}")->assertOk();
        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => 'salah-sekali'])
            ->assertStatus(422);

        $link = RecordShareLink::withoutGlobalScope('org')->find($id);
        $this->assertSame(0, $link->view_count, 'jatah hanya boleh terpakai oleh kata sandi yang benar');
        $this->assertTrue($link->isActive());
    }

    public function test_kata_sandi_benar_membuka_dokumen_tanpa_kolom_internal(): void
    {
        [$url, $password, , $ropa] = $this->terbitkan();
        $token = basename((string) $url);

        $res = $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => $password])->assertOk();

        $data = $res->json('data');
        $this->assertSame($ropa->registration_number, $data['registration_number']);
        $this->assertSame('PT Nusantara Sejahtera', $data['organization']);
        // Proses internal tidak ikut dibagikan walau penerimanya regulator.
        $this->assertArrayNotHasKey('assignees', $data);
        $this->assertArrayNotHasKey('review_notes', $data);
        $res->assertJsonPath('meta.remaining_views', 1);
    }

    public function test_tautan_mencabut_diri_setelah_jatah_habis(): void
    {
        [$url, $password, $id] = $this->terbitkan(['max_views' => 2]);
        $token = basename((string) $url);

        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => $password])->assertOk();
        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => $password])
            ->assertOk()
            ->assertJsonPath('meta.auto_revoked', true);

        // Pembukaan ketiga sudah ditolak di gerbang, dengan alasan yang jelas.
        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => $password])
            ->assertStatus(410)
            ->assertJsonPath('reason', RecordShareLink::REASON_MAX_VIEWS);

        $link = RecordShareLink::withoutGlobalScope('org')->find($id);
        $this->assertSame(2, $link->view_count);
        $this->assertFalse($link->isActive());
    }

    public function test_pencabutan_manual_dan_rotasi_mematikan_tautan_lama(): void
    {
        [$url, $password, $id] = $this->terbitkan();
        $token = basename((string) $url);

        $rotasi = $this->postJson("/api/ropa/tautan-lembaga/{$id}/rotasi")->assertOk();
        $tokenBaru = basename((string) $rotasi->json('data.url'));
        $sandiBaru = $rotasi->json('data.password');

        $this->assertNotSame($token, $tokenBaru);
        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => $password])->assertStatus(404);
        $this->postJson("/api/berbagi-publik/{$tokenBaru}/buka", ['password' => $sandiBaru])->assertOk();

        $this->postJson("/api/ropa/tautan-lembaga/{$id}/cabut")->assertOk();
        $this->postJson("/api/berbagi-publik/{$tokenBaru}/buka", ['password' => $sandiBaru])
            ->assertStatus(410)
            ->assertJsonPath('reason', RecordShareLink::REASON_MANUAL);
    }

    public function test_tautan_milik_organisasi_lain_tidak_bisa_dicabut(): void
    {
        [, , $id] = $this->terbitkan();

        $lain = Organization::factory()->create();
        $roleLain = TenantRole::create([
            'org_id' => $lain->id,
            'name' => 'admin',
            'slug' => 'admin-lain-'.uniqid(),
            'permissions' => ['ropa:read', 'ropa:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $lain->id, 'tenant_role_id' => $roleLain->id]));

        $this->postJson("/api/ropa/tautan-lembaga/{$id}/cabut")->assertStatus(404);
    }
}
