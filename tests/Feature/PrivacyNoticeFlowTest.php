<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alur penuh Privacy Notice: susun naskah, ajukan, setujui, terbitkan,
 * jadwalkan, dan sajikan ke publik dalam banyak bahasa.
 *
 * Penekanan test ini ada pada kasus NEGATIF, karena di situlah nilai modulnya.
 * Modul yang hanya bisa menerbitkan naskah tidak menambah apa pun di atas
 * kolom teks biasa; yang membuatnya berarti adalah naskah yang TIDAK dapat
 * berubah setelah disetujui, dan versi yang TIDAK bocor sebelum terbit.
 */
class PrivacyNoticeFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $editor;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Bank Uji',
            'slug' => 'bank-uji-'.uniqid(),
        ]);

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO',
            'permissions' => ['privacy_notice:read', 'privacy_notice:write'],
        ]);

        $this->editor = User::create([
            'org_id' => $this->org->id,
            'name' => 'Penyunting',
            'email' => 'editor'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);

        $this->approver = User::create([
            'org_id' => $this->org->id,
            'name' => 'Penyetuju',
            'email' => 'approver'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function createNotice(): array
    {
        Sanctum::actingAs($this->editor);

        $notice = $this->postJson('/api/privacy-notices', [
            'title' => 'Pemberitahuan Privasi Layanan Digital',
            'description' => 'Untuk kanal mobile dan web.',
        ])->assertStatus(201)->json('data');

        $version = $this->postJson("/api/privacy-notices/{$notice['id']}/versions", [
            'change_note' => 'Naskah awal',
            'contents' => [
                ['locale' => 'id', 'title' => 'Pemberitahuan Privasi', 'body' => 'Isi naskah bahasa Indonesia.'],
                ['locale' => 'en', 'title' => 'Privacy Notice', 'body' => 'English notice body.'],
            ],
        ])->assertStatus(201)->json('data');

        return [$notice, $version];
    }

    #[Test]
    public function alur_penuh_dari_draft_sampai_terbit_dan_tersaji_publik(): void
    {
        [$notice, $version] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', PrivacyNoticeVersion::STATUS_PENDING);

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', PrivacyNoticeVersion::STATUS_PUBLISHED);

        // Publik, tanpa otentikasi.
        $token = PrivacyNotice::withoutGlobalScope('org')->find($notice['id'])->embed_token;

        $this->getJson("/api/public/privacy-notice/{$token}")
            ->assertOk()
            ->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.title', 'Pemberitahuan Privasi')
            ->assertJsonPath('data.version', 1);

        $this->getJson("/api/public/privacy-notice/{$token}?locale=en")
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.title', 'Privacy Notice');
    }

    #[Test]
    public function bahasa_yang_belum_diterjemahkan_jatuh_ke_bahasa_baku_dan_mengakuinya(): void
    {
        [$notice, $version] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/approve")->assertOk();

        $token = PrivacyNotice::withoutGlobalScope('org')->find($notice['id'])->embed_token;

        $res = $this->getJson("/api/public/privacy-notice/{$token}?locale=ja")->assertOk();

        // Naskah yang disajikan adalah bahasa baku, TETAPI responsnya mengakui
        // bahwa itu bukan bahasa yang diminta — klien perlu tahu selisih ini
        // supaya penanda bahasa di antarmukanya tidak berbohong.
        $res->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.requested_locale', 'ja');
        $this->assertEqualsCanonicalizing(['id', 'en'], $res->json('data.available_locales'));
    }

    #[Test]
    public function naskah_terkunci_setelah_diajukan(): void
    {
        [$notice, $version] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")->assertOk();

        // Inilah inti modulnya: tanpa kunci ini, naskah dapat berubah setelah
        // disetujui dan sebelum terbit — yang tayang bukan yang disetujui.
        $this->putJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/content", [
            'contents' => [['locale' => 'id', 'title' => 'Diubah diam-diam', 'body' => 'Isi baru.']],
        ])->assertStatus(422);

        $this->assertSame(
            'Pemberitahuan Privasi',
            PrivacyNoticeVersion::withoutGlobalScope('org')->find($version['id'])
                ->contents()->withoutGlobalScope('org')->where('locale', 'id')->first()->title
        );
    }

    #[Test]
    public function pengaju_tidak_dapat_menyetujui_pengajuannya_sendiri(): void
    {
        [$notice, $version] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")->assertOk();

        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pengaju tidak dapat menyetujui pengajuannya sendiri.');
    }

    #[Test]
    public function versi_yang_belum_terbit_tidak_pernah_bocor_ke_publik(): void
    {
        [$notice, $version] = $this->createNotice();
        $token = PrivacyNotice::withoutGlobalScope('org')->find($notice['id'])->embed_token;

        // Masih draft.
        $this->getJson("/api/public/privacy-notice/{$token}")->assertStatus(404);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")->assertOk();

        // Menunggu persetujuan — tetap tidak boleh keluar.
        $this->getJson("/api/public/privacy-notice/{$token}")->assertStatus(404);
    }

    #[Test]
    public function penjadwalan_menahan_terbit_sampai_waktunya_lalu_perintah_menerbitkannya(): void
    {
        [$notice, $version] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/approve", [
            'publish_at' => now()->addDays(2)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', PrivacyNoticeVersion::STATUS_SCHEDULED);

        $token = PrivacyNotice::withoutGlobalScope('org')->find($notice['id'])->embed_token;
        $this->getJson("/api/public/privacy-notice/{$token}")->assertStatus(404);

        // Perintah terjadwal belum boleh menerbitkannya.
        $this->artisan('privacy-notices:publish-scheduled')->assertSuccessful();
        $this->getJson("/api/public/privacy-notice/{$token}")->assertStatus(404);

        // Setelah waktunya lewat, perintah yang sama menerbitkannya.
        $this->travel(3)->days();
        $this->artisan('privacy-notices:publish-scheduled')->assertSuccessful();
        $this->getJson("/api/public/privacy-notice/{$token}")
            ->assertOk()
            ->assertJsonPath('data.version', 1);
    }

    #[Test]
    public function versi_baru_menggantikan_versi_lama_dan_yang_lama_dipensiunkan(): void
    {
        [$notice, $v1] = $this->createNotice();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$v1['id']}/submit")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$v1['id']}/approve")->assertOk();

        // Versi kedua, menyalin naskah versi pertama.
        Sanctum::actingAs($this->editor);
        $v2 = $this->postJson("/api/privacy-notices/{$notice['id']}/versions", [
            'change_note' => 'Penyesuaian dasar hukum',
            'copy_from_version_id' => $v1['id'],
        ])->assertStatus(201)->json('data');

        // Naskah tersalin, jadi penyunting tidak perlu mengetik ulang.
        $this->assertCount(2, $this->getJson("/api/privacy-notices/{$notice['id']}")
            ->json('data.versions.0.contents'));

        $this->putJson("/api/privacy-notices/{$notice['id']}/versions/{$v2['id']}/content", [
            'contents' => [['locale' => 'id', 'title' => 'Pemberitahuan Privasi v2', 'body' => 'Isi diperbarui.']],
        ])->assertOk();
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$v2['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$v2['id']}/approve")->assertOk();

        $token = PrivacyNotice::withoutGlobalScope('org')->find($notice['id'])->embed_token;
        $this->getJson("/api/public/privacy-notice/{$token}")
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.title', 'Pemberitahuan Privasi v2');

        $this->assertSame(
            PrivacyNoticeVersion::STATUS_SUPERSEDED,
            PrivacyNoticeVersion::withoutGlobalScope('org')->find($v1['id'])->status
        );
    }

    #[Test]
    public function bahasa_baku_wajib_ada_sebelum_dapat_diajukan(): void
    {
        Sanctum::actingAs($this->editor);

        $notice = $this->postJson('/api/privacy-notices', [
            'title' => 'Hanya Inggris',
            'default_locale' => 'id',
        ])->assertStatus(201)->json('data');

        $version = $this->postJson("/api/privacy-notices/{$notice['id']}/versions", [
            'contents' => [['locale' => 'en', 'title' => 'English only', 'body' => 'Body.']],
        ])->assertStatus(201)->json('data');

        // Tanpa naskah bahasa baku, pengunjung yang meminta bahasa tak dikenal
        // tidak punya apa pun untuk disajikan.
        $this->postJson("/api/privacy-notices/{$notice['id']}/versions/{$version['id']}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Naskah bahasa baku (id) wajib diisi sebelum diajukan.');
    }

    #[Test]
    public function tanpa_izin_modul_permintaan_ditolak(): void
    {
        $roleTanpaIzin = TenantRole::create([
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
            'tenant_role_id' => $roleTanpaIzin->id,
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson('/api/privacy-notices')->assertStatus(403);
        $this->postJson('/api/privacy-notices', ['title' => 'X'])->assertStatus(403);
    }
}
