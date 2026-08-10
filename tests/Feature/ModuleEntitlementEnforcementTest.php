<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Penegakan entitlement modul di level request.
 *
 * Bug yang ditutup: entitlement dulu hanya menyaring sidebar. Modul yang
 * dicabut hilang dari menu, tetapi endpoint-nya tetap menjawab 200 — sehingga
 * mengetik URL-nya langsung tetap membuka datanya. Menyembunyikan menu bukan
 * keamanan.
 *
 * Yang dijaga di sini: pencabutan HARUS memblokir request, izin RBAC yang ada
 * tidak boleh menutupinya, dan tenant tanpa record entitlement (mayoritas)
 * tidak boleh ikut terkunci.
 */
class ModuleEntitlementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private MenuItem $dpiaMenu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        // Menu DPIA harus ada agar entitlement dapat dipetakan ke menu_id-nya.
        $this->dpiaMenu = MenuItem::create([
            'menu_key' => 'dpia',
            'label' => 'DPIA',
            'href' => '/dpia',
            'icon' => 'ShieldAlert',
            'section' => 'PDP Modules',
            'sort_order' => 100,
        ]);
    }

    private function tenantUser(array $permissions = ['*']): User
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Admin Uji',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => $permissions,
        ]);

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function revokeDpia(): void
    {
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id,
            'menu_id' => $this->dpiaMenu->id,
            'is_entitled' => false,
        ]);
    }

    public function test_modul_dicabut_memblokir_request_walau_izin_ada(): void
    {
        // Inti bug. Role punya izin penuh ('*'), tetapi organisasinya tidak
        // lagi memiliki modulnya. Mengetik URL DPIA harus 403, bukan 200.
        $user = $this->tenantUser(['*']);
        $this->revokeDpia();
        Sanctum::actingAs($user);

        $this->getJson('/api/m/dpia')->assertStatus(403);
    }

    public function test_modul_dicabut_memblokir_route_ber_middleware_permission(): void
    {
        // Jalur kedua: route non-universal yang dijaga CheckPermission.
        $user = $this->tenantUser(['*']);
        $this->revokeDpia();
        Sanctum::actingAs($user);

        // Route apa pun di bawah permission:dpia,* harus ikut terkunci. Diuji
        // lewat endpoint kategori kerangka DPIA yang memakai middleware itu.
        $this->getJson('/api/dpia/framework/categories')->assertStatus(403);
    }

    public function test_tanpa_record_entitlement_akses_tetap_terbuka(): void
    {
        // Mayoritas tenant tidak punya satu pun record. Mereka tidak boleh
        // ikut terkunci hanya karena penegakan diaktifkan.
        $user = $this->tenantUser(['*']);
        Sanctum::actingAs($user);

        $this->getJson('/api/m/dpia')->assertStatus(200);
    }

    public function test_entitlement_diberikan_eksplisit_tetap_terbuka(): void
    {
        $user = $this->tenantUser(['*']);
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id,
            'menu_id' => $this->dpiaMenu->id,
            'is_entitled' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/m/dpia')->assertStatus(200);
    }

    public function test_entitlement_kedaluwarsa_diperlakukan_seperti_dicabut(): void
    {
        $user = $this->tenantUser(['*']);
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id,
            'menu_id' => $this->dpiaMenu->id,
            'is_entitled' => true,
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/m/dpia')->assertStatus(403);
    }

    public function test_pencabutan_modul_lain_tidak_memblokir_modul_ini(): void
    {
        // Pencabutan harus tepat sasaran: mencabut DPIA tidak boleh menyentuh
        // RoPA. Diuji dengan mencabut DPIA lalu mengakses RoPA.
        $ropaMenu = MenuItem::create([
            'menu_key' => 'ropa', 'label' => 'RoPA', 'href' => '/ropa',
            'icon' => 'FileText', 'section' => 'PDP Modules', 'sort_order' => 90,
        ]);
        $user = $this->tenantUser(['*']);
        $this->revokeDpia();
        Sanctum::actingAs($user);

        $this->getJson('/api/m/ropa')->assertStatus(200);
        $this->assertNotNull($ropaMenu->id);
    }

    public function test_superadmin_tidak_terpengaruh_pencabutan_tenant(): void
    {
        // Platform staff mengelola semua tenant. Entitlement satu tenant tidak
        // membatasi mereka — kalau ikut terkunci, mereka tidak bisa menolong
        // tenant yang justru sedang bermasalah dengan langganannya.
        $this->revokeDpia();

        $superadmin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'superadmin',
        ]);
        Sanctum::actingAs($superadmin);

        $this->getJson('/api/m/dpia')->assertStatus(200);
    }
}
