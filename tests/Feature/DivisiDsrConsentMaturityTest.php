<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\AssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tiga keputusan produk yang berbeda mekanismenya:
 *
 *   GAP      → terbuka untuk semua pemegang izin modul, TANPA saringan divisi.
 *   Maturity → dibatasi peran: bawaannya DPO + admin tenant saja.
 *   DSR      → punya divisi; yang masuk dari luar terlihat semua sampai
 *              ditriase, kecuali aplikasinya sudah dipetakan ke satu divisi.
 *   Consent  → punya divisi, seperti RoPA.
 */
class DivisiDsrConsentMaturityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /** @param array<int, string> $izin */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin): User
    {
        $departemen = $divisi ? Department::firstOrCreate([
            'org_id' => $this->org->id,
            'name' => $divisi,
        ]) : null;

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => $namaPeran,
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    /**
     * Jalankan migrasi pencabutan izin maturity.
     *
     * Dipanggil LANGSUNG, bukan lewat `artisan migrate --path`: RefreshDatabase
     * sudah menjalankan seluruh migrasi, sehingga migrator akan MELEWATINYA
     * karena tercatat pernah jalan — dan ujinya lulus tanpa satu baris pun
     * kode migrasi dieksekusi.
     */
    private function jalankanPencabutan(): void
    {
        $migrasi = require database_path('migrations/2026_09_15_000004_revoke_maturity_from_maker_viewer_roles.php');
        $migrasi->up();
    }

    private const IZIN_STAF = [
        'dsr:read', 'dsr:write', 'consent:read', 'consent:write',
        'gap_assessment:read', 'gap_assessment:write',
    ];

    // ───────────────────────── DSR ─────────────────────────

    /** @param array<string, mixed> $tambahan */
    private function buatDsr(array $tambahan = []): array
    {
        return $this->postJson('/api/m/dsr', array_merge([
            'request_type' => 'access',
            'requester_name' => 'Budi',
            'requester_email' => 'budi@contoh.id',
            'status' => 'pending',
        ], $tambahan))->assertSuccessful()->json('data');
    }

    /** @return array<int, string> */
    private function dsrTerlihat(): array
    {
        return array_column($this->getJson('/api/m/dsr')->assertOk()->json('data'), 'request_id');
    }

    #[Test]
    public function dsr_buatan_staf_otomatis_masuk_divisinya(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));

        $dsr = $this->buatDsr();

        $this->assertSame('HR', $dsr['assign_group']);
        $this->assertSame('HR', $dsr['origin_division']);
    }

    #[Test]
    public function dsr_divisi_lain_tidak_terlihat(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));
        $milikHr = $this->buatDsr()['request_id'];

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'Keuangan', self::IZIN_STAF));
        $milikKeuangan = $this->buatDsr()['request_id'];

        $terlihat = $this->dsrTerlihat();
        $this->assertContains($milikKeuangan, $terlihat);
        $this->assertNotContains($milikHr, $terlihat);
    }

    #[Test]
    public function dsr_divisi_asal_tidak_bisa_dilepas(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));
        $dsr = $this->buatDsr();

        $this->putJson("/api/m/dsr/{$dsr['id']}", ['assign_group' => 'Keuangan'])->assertSuccessful();

        $this->assertSame(
            'Keuangan'.AssignmentScope::DELIM.'HR',
            DsrRequest::withoutGlobalScope('org')->find($dsr['id'])->assign_group,
        );
    }

    #[Test]
    public function dsr_masuk_dari_luar_tanpa_divisi_terlihat_semua_orang(): void
    {
        // Permohonan dari aplikasi yang BELUM dipetakan ke divisi mana pun.
        // Ia tidak boleh tersembunyi — tenggat 3x24 jam tetap berjalan, dan
        // permohonan yang tak terlihat siapa pun adalah kegagalan yang jauh
        // lebih mahal daripada permohonan yang terlihat terlalu banyak orang.
        $masuk = DsrRequest::create([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-LUAR',
            'request_type' => 'access',
            'requester_name' => 'Citra',
            'requester_email' => 'citra@contoh.id',
            'status' => 'pending_verification',
            'deadline_at' => now()->addHours(72),
        ]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));

        $this->assertContains($masuk->request_id, $this->dsrTerlihat());
    }

    #[Test]
    public function aplikasi_dsr_dapat_menyimpan_divisi_bawaan(): void
    {
        // Kolomnya yang menjadikan "ada bidang-bidangnya" mungkin: permohonan
        // dari portal HR jatuh ke HR tanpa triase manual.
        $app = DsrApp::create([
            'org_id' => $this->org->id,
            'name' => 'Portal HR',
            'app_code' => 'hr-'.substr(uniqid(), -6),
            'default_division' => 'HR',
        ]);

        $this->assertSame('HR', $app->fresh()->default_division);
    }

    // ─────────────────────── Consent ───────────────────────

    /** @return array<int, string> */
    private function consentTerlihat(): array
    {
        return array_column($this->getJson('/api/m/consent')->assertOk()->json('data'), 'name');
    }

    private function buatConsent(string $nama): array
    {
        return $this->postJson('/api/m/consent', [
            'name' => $nama,
            'kind' => 'cookie_banner',
            'domain' => 'contoh.id',
        ])->assertSuccessful()->json('data');
    }

    #[Test]
    public function consent_buatan_staf_otomatis_masuk_divisinya(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));

        $titik = $this->buatConsent('Banner Karier');

        $this->assertSame('HR', $titik['assign_group']);
        $this->assertSame('HR', $titik['origin_division']);
    }

    #[Test]
    public function consent_divisi_lain_tidak_terlihat(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));
        $this->buatConsent('Banner Karier');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'Keuangan', self::IZIN_STAF));
        $this->buatConsent('Banner Pembayaran');

        $nama = $this->consentTerlihat();
        $this->assertContains('Banner Pembayaran', $nama);
        $this->assertNotContains('Banner Karier', $nama);
    }

    #[Test]
    public function dpo_melihat_seluruh_dsr_dan_consent(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));
        $milikHr = $this->buatDsr()['request_id'];
        $this->buatConsent('Banner Karier');

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'Keuangan', ['*']));

        $this->assertContains($milikHr, $this->dsrTerlihat());
        $this->assertContains('Banner Karier', $this->consentTerlihat());
    }

    // ───────────────────────── GAP ─────────────────────────

    #[Test]
    public function gap_tidak_disaring_divisi(): void
    {
        // GAP adalah penilaian SATU organisasi, bukan milik divisi mana pun.
        // Uji ini mengunci niat itu supaya tidak ikut tersapu kalau suatu saat
        // ada penyisiran "semua modul disaring divisi".
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));
        $gap = $this->postJson('/api/gap', ['title' => 'Asesmen 2026'])->assertSuccessful()->json();
        $id = $gap['data']['id'] ?? $gap['id'] ?? null;
        $this->assertNotNull($id);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'Keuangan', self::IZIN_STAF));

        $daftar = $this->getJson('/api/gap')->assertOk()->json('data');
        $this->assertContains($id, array_column($daftar, 'id'));
    }

    // ─────────────────────── Maturity ──────────────────────

    #[Test]
    public function maturity_tertutup_untuk_staf_biasa(): void
    {
        // Sebelumnya grup rute ini TIDAK punya gerbang izin sama sekali.
        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR', self::IZIN_STAF));

        $this->getJson('/api/maturity')->assertForbidden();
    }

    #[Test]
    public function maturity_terbuka_untuk_dpo(): void
    {
        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR', ['*']));

        $this->getJson('/api/maturity')->assertOk();
    }

    #[Test]
    public function maturity_terbuka_untuk_yang_diberi_izin_secara_sadar(): void
    {
        // Bukti maturity lazimnya diunggah tim IT/keamanan di bawah supervisi
        // DPO — jalan itu harus tetap ada, hanya kini lewat pemberian sadar.
        Sanctum::actingAs($this->pengguna('maker', 'Tim Keamanan', 'IT', ['maturity:read']));

        $this->getJson('/api/maturity')->assertOk();
    }

    #[Test]
    public function maturity_izin_baca_saja_tidak_bisa_menulis(): void
    {
        Sanctum::actingAs($this->pengguna('maker', 'Tim Keamanan', 'IT', ['maturity:read']));

        $this->postJson('/api/maturity', ['title' => 'Percobaan'])->assertForbidden();
    }

    #[Test]
    public function role_maker_bawaan_tidak_lagi_memegang_maturity(): void
    {
        // Migrasi pencabutan berlaku untuk role bawaan yang sudah terlanjur
        // memegangnya. Di sini diuji bentuk seed-nya: Maker bawaan tidak boleh
        // lagi lahir dengan izin maturity.
        $maker = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Maker',
            'slug' => 'maker-'.uniqid(),
            'is_system' => true,
            'permissions' => ['ropa:read', 'ropa:write', 'maturity:read', 'maturity:write'],
        ]);

        $this->jalankanPencabutan();

        $izin = $maker->fresh()->permissions;
        $this->assertContains('ropa:write', $izin);
        $this->assertNotContains('maturity:read', $izin);
        $this->assertNotContains('maturity:write', $izin);
    }

    #[Test]
    public function pencabutan_tidak_menyentuh_role_kustom(): void
    {
        // Tenant yang sengaja memberi maturity ke role buatannya sendiri tidak
        // boleh ikut dicabut — migrasi hanya menyasar DUA nama role bawaan.
        $kustom = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Analis Kepatuhan',
            'slug' => 'analis-'.uniqid(),
            'is_system' => false,
            'permissions' => ['maturity:read', 'maturity:write'],
        ]);

        $this->jalankanPencabutan();

        $this->assertContains('maturity:write', $kustom->fresh()->permissions);
    }
}
