<?php

namespace Tests\Feature;

use App\Models\EmbedToken;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\RecordShareLink;
use App\Models\Ropa;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Entitlement pada JALUR INTEGRASI (kunci API mitra, embed, tautan lembaga).
 *
 * Lubang yang ditutup: entitlement selama ini ditegakkan lewat CheckPermission,
 * sehingga hanya berlaku pada rute yang memakai `permission:`. Jalur integrasi
 * tidak memakainya sama sekali — kunci API dan tautan publik punya cara
 * autentikasi sendiri. Akibatnya tenant yang modulnya DICABUT tetap bisa
 * membaca dan menulis lewat API, dan embed yang terbit sebelum pencabutan tetap
 * menyajikan data sesudahnya.
 *
 * Dua arah dijaga di sini, dan arah kedua sama pentingnya: pencabutan harus
 * memblokir, TETAPI tenant tanpa record entitlement — yaitu mayoritas tenant —
 * tidak boleh ikut terkunci. Salah di arah itu berarti mematikan API semua
 * orang sekaligus.
 */
class IntegrasiEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->user = User::factory()->create(['org_id' => $this->org->id]);
    }

    private function menu(string $key): MenuItem
    {
        return MenuItem::create([
            'menu_key' => $key,
            'label' => strtoupper($key),
            'href' => '/'.$key,
            'icon' => 'Shield',
            'section' => 'PDP Modules',
            'sort_order' => 100,
        ]);
    }

    private function cabut(MenuItem $menu): void
    {
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id,
            'menu_id' => $menu->id,
            'is_entitled' => false,
        ]);

        // EntitlementService diikat `scoped` — satu instans per daur hidup
        // request, dan ia menyimpan cache menu yang dicabut. Di produksi itu
        // benar: php-fpm membangun container baru tiap request, sedangkan
        // Octane dan queue worker memanggil forgetScopedInstances() di batas
        // request.
        //
        // Harness uji TIDAK menyediakan batas itu: beberapa panggilan HTTP dalam
        // satu metode uji berbagi container yang sama, sehingga cache dari
        // panggilan sebelum pencabutan akan bocor ke panggilan sesudahnya.
        // Memanggilnya di sini MENIRUKAN batas request yang sesungguhnya —
        // bukan melonggarkan ujinya, karena fase "terbuka sebelum dicabut"
        // justru yang membuktikan pencabutan benar-benar mengubah keadaan.
        $this->app->forgetScopedInstances();
    }

    /** @param array<int, string> $permissions */
    private function kunci(array $permissions): array
    {
        return ['X-Api-Key' => PartnerApiKey::generateKey([
            'org_id' => $this->org->id,
            'name' => 'Sistem Mitra',
            'permissions' => $permissions,
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            'created_by' => $this->user->id,
        ])['key']];
    }

    private function ropa(): Ropa
    {
        return Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-777',
            'processing_activity' => 'Pembukaan Rekening',
        ]);
    }

    // ---------- Kunci API mitra ----------

    public function test_modul_dicabut_memblokir_baca_lewat_kunci_api(): void
    {
        $this->ropa();
        $ropaMenu = $this->menu('ropa');

        // Sebelum dicabut: terbuka.
        $this->getJson('/api/v1/ropa', $this->kunci(['ropa.read']))->assertOk();

        $this->cabut($ropaMenu);

        $this->getJson('/api/v1/ropa', $this->kunci(['ropa.read']))->assertStatus(403);
    }

    public function test_modul_dicabut_memblokir_tulis_lewat_kunci_api(): void
    {
        $this->cabut($this->menu('ropa'));

        $this->postJson('/api/v1/ropa', [
            'processing_activity' => 'Kegiatan Baru',
        ], $this->kunci(['ropa.write']))->assertStatus(403);

        $this->assertSame(0, Ropa::withoutGlobalScope('org')->count(), 'tidak boleh ada record yang terlanjur tertulis');
    }

    public function test_breach_ikut_terjaga_walau_kuncinya_berscope_bintang(): void
    {
        // Grup Breach v1 memanggil middleware TANPA argumen scope, sehingga peta
        // entitlement berbasis scope akan melewatkannya sama sekali. Inilah yang
        // membuat petanya diambil dari path rute.
        $this->cabut($this->menu('breach'));

        $this->getJson('/api/v1/breach', $this->kunci(['*']))->assertStatus(403);
    }

    public function test_tenant_tanpa_record_entitlement_tetap_terbuka(): void
    {
        $this->ropa();
        // Tidak ada MenuItem, tidak ada record entitlement — keadaan mayoritas
        // tenant. Menutup mereka berarti mematikan API semua orang.
        $this->getJson('/api/v1/ropa', $this->kunci(['ropa.read']))->assertOk();
    }

    public function test_pencabutan_modul_lain_tidak_memblokir_modul_ini(): void
    {
        $this->ropa();
        $this->menu('ropa');
        $this->cabut($this->menu('dpia'));

        $this->getJson('/api/v1/ropa', $this->kunci(['ropa.read']))->assertOk();
    }

    // ---------- Embed & tautan lembaga ----------

    public function test_embed_berhenti_menyajikan_setelah_modul_dicabut(): void
    {
        $this->ropa();
        $embed = EmbedToken::create([
            'org_id' => $this->org->id,
            'module' => 'ropa',
            'label' => 'Register publik',
            'token' => EmbedToken::generateUniqueToken(),
            'expires_at' => now()->addDays(30),
        ]);
        $token = EmbedToken::withoutGlobalScope('org')->find($embed->id)->token;

        $this->getJson("/api/embed-publik/{$token}/data")->assertOk();

        $this->cabut($this->menu('ropa'));

        // Tautan yang terbit saat modul masih aktif tidak boleh terus menyajikan
        // data sesudah modulnya tidak lagi dibeli.
        $this->getJson("/api/embed-publik/{$token}/data")->assertStatus(403);
    }

    public function test_tautan_lembaga_tertutup_setelah_modul_dicabut(): void
    {
        $ropa = $this->ropa();
        $link = RecordShareLink::create([
            'org_id' => $this->org->id,
            'module' => 'ropa',
            'record_id' => $ropa->id,
            'token' => RecordShareLink::generateUniqueToken(),
            'password_hash' => Hash::make('RAHASIA-1234'),
            'max_views' => 5,
            'expires_at' => now()->addDays(30),
        ]);
        $token = RecordShareLink::withoutGlobalScope('org')->find($link->id)->token;

        $this->getJson("/api/berbagi-publik/{$token}")->assertOk();

        $this->cabut($this->menu('ropa'));

        $this->getJson("/api/berbagi-publik/{$token}")->assertStatus(403);
        // Ditolak di gerbang, sebelum kata sandi sempat diperiksa.
        $this->postJson("/api/berbagi-publik/{$token}/buka", ['password' => 'RAHASIA-1234'])
            ->assertStatus(403);
    }
}
