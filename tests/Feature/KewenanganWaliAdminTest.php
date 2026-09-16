<?php

namespace Tests\Feature;

use App\Mail\GuardianVerificationMail;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Department;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\Consent\LayananWali;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint admin kewenangan wali — PP 33/2026 Pasal 38 & 39.
 *
 * Yang dijaga: dua lapis penyaringan (tenant, lalu divisi lewat titik
 * pengumpulan), tidak bocornya token/pilihan menunggu, pencabutan yang
 * tercatat siapa-dan-mengapa, dan kirim-ulang yang menolak terbuka.
 */
class KewenanganWaliAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    // ───────────────────────── bantu ─────────────────────────

    private function pengguna(string $role, ?string $divisi, array $izin = ['consent:read', 'consent:write'], ?Organization $org = null): User
    {
        $org ??= $this->org;
        $departemen = $divisi ? Department::create(['org_id' => $org->id, 'name' => $divisi]) : null;

        return User::factory()->create([
            'org_id' => $org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $org->id,
                'name' => 'peran-'.uniqid(),
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    private function titik(string $nama, ?string $divisi = null, ?Organization $org = null): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => ($org ?? $this->org)->id,
            'collection_id' => 'CNT-'.substr(uniqid(), -6),
            'name' => $nama,
            'kind' => ConsentCollectionPoint::KIND_APP,
            'assign_group' => $divisi,
        ]);
    }

    /**
     * @param  'menunggu'|'terverifikasi'|'dicabut'  $keadaan
     */
    private function kewenangan(ConsentCollectionPoint $cp, string $penanda, string $keadaan = 'menunggu', string $kontakWali = 'siti@contoh.id', array $subjek = []): GuardianConsent
    {
        $s = ConsentSubject::temukanAtauBuat($cp->org_id, $penanda, array_merge(['subject_class' => KelasSubjek::ANAK], $subjek));
        $w = Guardian::temukanAtauBuat($cp->org_id, $kontakWali, ['name' => 'Siti R.', 'relationship' => 'orang_tua']);

        $kw = GuardianConsent::create([
            'org_id' => $cp->org_id,
            'consent_subject_id' => $s->id,
            'guardian_id' => $w->id,
            'collection_point_id' => $cp->id,
        ]);

        if ($keadaan === 'menunggu') {
            $kw->forceFill(['pending_capture' => ['user_identifier' => $penanda, 'consented_items' => ['x' => true]]])->save();
            $kw->terbitkanToken();
        } else {
            $kw->forceFill([
                'verified_at' => now()->subDay(),
                'verification_method_code' => 'otp_email',
                'verification_driver' => 'otp',
                'verification_confidence' => 'rendah',
                'statement_shown' => 'Saya, Siti R., selaku orang tua …',
            ])->save();
            if ($keadaan === 'dicabut') {
                $kw->cabut('manual', 'hak asuh berpindah');
            }
        }

        return $kw->fresh();
    }

    // ───────────────────────── penyaringan ─────────────────────────

    #[Test]
    public function daftar_hanya_memuat_kewenangan_tenant_sendiri(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $this->kewenangan($cp, 'anak@contoh.id');

        $lain = Organization::factory()->create();
        $this->kewenangan($this->titik('Formulir Lain', null, $lain), 'anak@contoh.id', 'menunggu', 'wali@lain.id');

        Sanctum::actingAs($this->pengguna('admin', null));

        $this->getJson('/api/guardian-consents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.label', 'anak@contoh.id')
            ->assertJsonPath('data.0.status', 'menunggu_wali');
    }

    #[Test]
    public function staf_divisi_hanya_melihat_kewenangan_dari_titik_divisinya(): void
    {
        $hr = $this->titik('Formulir HR', 'HR');
        $keuangan = $this->titik('Formulir Keuangan', 'Finance');
        $this->kewenangan($hr, 'a@contoh.id');
        $this->kewenangan($keuangan, 'b@contoh.id', 'menunggu', 'wali-b@contoh.id');

        Sanctum::actingAs($this->pengguna('maker', 'HR'));
        $this->getJson('/api/guardian-consents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.collection_point.name', 'Formulir HR');

        // Detail milik divisi lain pun tidak ditemukan — bukan 403 yang
        // membocorkan bahwa id-nya ada.
        $milikKeuangan = GuardianConsent::whereHas('collectionPoint', fn ($q) => $q->where('name', 'Formulir Keuangan'))->first();
        $this->getJson('/api/guardian-consents/'.$milikKeuangan->id)->assertStatus(404);

        Sanctum::actingAs($this->pengguna('admin', null));
        $this->getJson('/api/guardian-consents')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function filter_status_bekerja(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $this->kewenangan($cp, 'a@contoh.id', 'menunggu', 'w1@contoh.id');
        $this->kewenangan($cp, 'b@contoh.id', 'terverifikasi', 'w2@contoh.id');
        $this->kewenangan($cp, 'c@contoh.id', 'dicabut', 'w3@contoh.id');

        Sanctum::actingAs($this->pengguna('admin', null));

        $this->getJson('/api/guardian-consents?status=menunggu_wali')->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.label', 'a@contoh.id');
        $this->getJson('/api/guardian-consents?status=terverifikasi')->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.label', 'b@contoh.id');
        $this->getJson('/api/guardian-consents?status=dicabut')->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.label', 'c@contoh.id');
        $this->getJson('/api/guardian-consents')->assertJsonCount(3, 'data');
    }

    #[Test]
    public function pencarian_lewat_kontak_wali_dan_penanda_subjek_dinormalkan(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $this->kewenangan($cp, 'anak@contoh.id', 'menunggu', 'siti@contoh.id');
        $this->kewenangan($cp, 'lain@contoh.id', 'menunggu', 'budi@contoh.id');

        Sanctum::actingAs($this->pengguna('admin', null));

        // Kolomnya tersandi — pencarian lewat hash ternormalkan, bukan LIKE.
        $this->getJson('/api/guardian-consents?search='.urlencode(' Siti@Contoh.ID '))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.guardian.contact', 'siti@contoh.id');
        $this->getJson('/api/guardian-consents?search='.urlencode('LAIN@contoh.id'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.label', 'lain@contoh.id');
        $this->getJson('/api/guardian-consents?search=tidakada@contoh.id')->assertJsonCount(0, 'data');
    }

    // ───────────────────────── detail ─────────────────────────

    #[Test]
    public function detail_memuat_consent_yang_dipayungi_tanpa_membocorkan_token_atau_pilihan_menunggu(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $kw = $this->kewenangan($cp, 'anak@contoh.id', 'terverifikasi');
        ConsentLog::create([
            'org_id' => $this->org->id,
            'collection_id' => $cp->id,
            'user_identifier' => 'anak@contoh.id',
            'consented_items' => ['pemasaran' => true],
            'guardian_consent_id' => $kw->id,
            'subject_class' => KelasSubjek::ANAK,
        ]);

        Sanctum::actingAs($this->pengguna('admin', null));

        $r = $this->getJson('/api/guardian-consents/'.$kw->id)->assertOk()
            ->assertJsonPath('data.status', 'terverifikasi')
            ->assertJsonPath('data.verification.method_code', 'otp_email')
            ->assertJsonPath('data.verification.confidence', 'rendah')
            ->assertJsonPath('data.guardian.name', 'Siti R.')
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.subject_class', 'anak');

        $this->assertStringNotContainsString('verification_token_hash', $r->getContent());
        $this->assertStringNotContainsString('pending_capture', $r->getContent());
    }

    // ───────────────────────── pencabutan ─────────────────────────

    #[Test]
    public function pencabutan_mencatat_alasan_dan_pelaku_lalu_menolak_pencabutan_kedua(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $kw = $this->kewenangan($cp, 'anak@contoh.id', 'terverifikasi');
        $admin = $this->pengguna('admin', null);
        Sanctum::actingAs($admin);

        $this->postJson('/api/guardian-consents/'.$kw->id.'/revoke', ['note' => 'Hak asuh berpindah ke ayah.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dicabut')
            ->assertJsonPath('data.revoke_reason', 'manual')
            ->assertJsonPath('data.revoke_note', 'Hak asuh berpindah ke ayah.')
            ->assertJsonPath('data.revoked_by', $admin->id);

        $this->assertSame(1, AuditLog::where('action', 'guardian_consent.revoke')->where('record_id', $kw->id)->count());

        // Pencabutan kedua ditolak supaya alasan pertama tidak tertimpa.
        $this->postJson('/api/guardian-consents/'.$kw->id.'/revoke', ['note' => 'lain'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUDAH_DICABUT');
        $this->assertSame('Hak asuh berpindah ke ayah.', $kw->fresh()->revoke_note);
    }

    // ───────────────────────── kirim ulang ─────────────────────────

    #[Test]
    public function kirim_ulang_menerbitkan_token_baru_dan_mengantrekan_surel(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $kw = $this->kewenangan($cp, 'anak@contoh.id', 'menunggu');
        $hashLama = $kw->verification_token_hash;

        Sanctum::actingAs($this->pengguna('admin', null));

        $this->postJson('/api/guardian-consents/'.$kw->id.'/resend')->assertOk()->assertJsonStructure(['expires_at']);

        $this->assertNotSame($hashLama, $kw->fresh()->verification_token_hash);
        Mail::assertQueued(GuardianVerificationMail::class, fn ($m) => $m->hasTo('siti@contoh.id'));
        $this->assertSame(1, AuditLog::where('action', 'guardian_consent.resend')->count());
    }

    #[Test]
    public function kirim_ulang_ditolak_terbuka_bila_dicabut_atau_tidak_ada_yang_menunggu(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $dicabut = $this->kewenangan($cp, 'a@contoh.id', 'dicabut', 'w1@contoh.id');
        $selesai = $this->kewenangan($cp, 'b@contoh.id', 'terverifikasi', 'w2@contoh.id');

        Sanctum::actingAs($this->pengguna('admin', null));

        $this->postJson('/api/guardian-consents/'.$dicabut->id.'/resend')
            ->assertStatus(422)->assertJsonPath('code', LayananWali::KEWENANGAN_DICABUT);
        $this->postJson('/api/guardian-consents/'.$selesai->id.'/resend')
            ->assertStatus(409)->assertJsonPath('code', LayananWali::TIDAK_ADA_YANG_MENUNGGU);

        Mail::assertNothingQueued();
    }

    // ───────────────────────── izin ─────────────────────────

    #[Test]
    public function tanpa_izin_consent_ditolak(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $kw = $this->kewenangan($cp, 'anak@contoh.id', 'terverifikasi');

        Sanctum::actingAs($this->pengguna('maker', null, ['ropa:read']));
        $this->getJson('/api/guardian-consents')->assertStatus(403);

        // Baca saja tidak cukup untuk mencabut.
        Sanctum::actingAs($this->pengguna('maker', null, ['consent:read']));
        $this->getJson('/api/guardian-consents')->assertOk();
        $this->postJson('/api/guardian-consents/'.$kw->id.'/revoke')->assertStatus(403);
        $this->assertNull($kw->fresh()->revoked_at);
    }

    // ───────────────────────── statistik ─────────────────────────

    #[Test]
    public function statistik_menghitung_per_keadaan_dan_peralihan_yang_segera(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        $this->kewenangan($cp, 'a@contoh.id', 'menunggu', 'w1@contoh.id');
        $this->kewenangan($cp, 'b@contoh.id', 'terverifikasi', 'w2@contoh.id', ['transition_date' => now()->addDays(10)->toDateString()]);
        $this->kewenangan($cp, 'c@contoh.id', 'terverifikasi', 'w3@contoh.id', ['transition_date' => now()->addYears(3)->toDateString()]);
        $this->kewenangan($cp, 'd@contoh.id', 'dicabut', 'w4@contoh.id');

        Sanctum::actingAs($this->pengguna('admin', null));

        $this->getJson('/api/guardian-consents/stats')
            ->assertOk()
            ->assertJsonPath('data.menunggu_wali', 1)
            ->assertJsonPath('data.terverifikasi', 2)
            ->assertJsonPath('data.dicabut', 1)
            ->assertJsonPath('data.peralihan_segera', 1);
    }
}
