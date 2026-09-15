<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemilih sumber di form "Buat TIA": RoPA, Transfer Lintas Negara, Pihak Ketiga.
 *
 * Ketiganya bekerja dua tahap — daftar opsinya diambil dari endpoint modul
 * masing-masing, lalu memilih satu opsi mem-POST /tia/from-* untuk membuat draft
 * ber-prefill. Uji ini menembak KEDUA tahap, karena tahap pertama yang diam-diam
 * kosong dan tahap kedua yang 500 terlihat sama saja dari sisi pengguna: "tidak
 * bisa pilih".
 */
class TiaSumberPickerTest extends TestCase
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
            'org_id' => $this->org->id, 'name' => 'Admin', 'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'tenant_role_id' => $role->id,
        ]);
        Sanctum::actingAs($this->user);
    }

    private function transferLintasNegara(): array
    {
        return $this->postJson('/api/cross-border', [
            'destination_country' => 'Singapura',
            'destination_entity' => 'AWS SG',
            'transfer_purpose' => 'Hosting basis data nasabah',
            'legal_basis' => 'adequacy',
        ])->assertSuccessful()->json('data');
    }

    #[Test]
    public function daftar_opsi_transfer_lintas_negara_membawa_label_yang_bisa_ditampilkan(): void
    {
        $this->transferLintasNegara();

        $baris = $this->getJson('/api/cross-border?per_page=200')->assertOk()->json('data');
        $this->assertNotEmpty($baris, 'dropdown CBDT kosong — tidak ada yang bisa dipilih');

        // Label di form dirakit dari kolom-kolom ini. Kalau namanya salah,
        // dropdown-nya terisi tapi tiap barisnya terbaca "undefined".
        $this->assertNotNull($baris[0]['destination_country'] ?? null);
        $this->assertNotNull(
            $baris[0]['destination_entity'] ?? $baris[0]['transfer_purpose'] ?? null,
            'CBDT harus punya nama yang bisa dibaca manusia selain negara tujuan',
        );
    }

    #[Test]
    public function daftar_opsi_pihak_ketiga_membawa_label_yang_bisa_ditampilkan(): void
    {
        Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data', 'country' => 'Singapura']);

        $baris = $this->getJson('/api/vendor-risk?per_page=200')->assertOk()->json('data');
        $this->assertNotEmpty($baris, 'dropdown pihak ketiga kosong — tidak ada yang bisa dipilih');

        $this->assertSame('PT Awan Data', $baris[0]['name'] ?? null);
    }

    #[Test]
    public function memilih_transfer_lintas_negara_membuat_draft_tia(): void
    {
        $cbt = $this->transferLintasNegara();

        $res = $this->postJson("/api/tia/from-cross-border/{$cbt['id']}")->assertSuccessful()->json('data');

        $this->assertSame($cbt['id'], $res['linked_cross_border_id']);
        $this->assertSame('Singapura', $res['destination_country']);
        $this->assertNotEmpty($res['tia_code']);
    }

    #[Test]
    public function memilih_pihak_ketiga_membuat_draft_tia(): void
    {
        $pihak = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data', 'country' => 'Singapura']);

        $res = $this->postJson("/api/tia/from-vendor/{$pihak->id}")->assertSuccessful()->json('data');

        $this->assertSame($pihak->id, $res['linked_vendor_id']);
        $this->assertStringContainsString('PT Awan Data', $res['title']);
        $this->assertNotEmpty($res['tia_code']);
    }

    #[Test]
    public function memilih_ropa_membuat_draft_tia(): void
    {
        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -6),
            'processing_activity' => 'Rekrutmen karyawan',
            'status' => 'draft',
        ]);

        $res = $this->postJson("/api/tia/from-ropa/{$ropa->id}")->assertSuccessful()->json('data');

        $this->assertSame($ropa->id, $res['linked_ropa_id']);
        $this->assertNotEmpty($res['tia_code']);
    }

    #[Test]
    public function detail_tia_mengirim_nama_pihak_ketiga_dan_transfer_terkait(): void
    {
        // Yang dipakai halaman detail + ekspor untuk menyebut sumbernya.
        $cbt = $this->transferLintasNegara();
        $dibuat = $this->postJson("/api/tia/from-cross-border/{$cbt['id']}")->assertSuccessful()->json('data');

        $detail = $this->getJson("/api/tia/{$dibuat['id']}")->assertOk()->json('data');

        // Eloquent `toArray()` menserialisasi relasi dalam snake_case, jadi
        // kuncinya `cross_border` — BUKAN `crossBorder`. Frontend sempat
        // membacanya sebagai camelCase dan karena itu seluruh blok "transfer
        // terkait" tidak pernah tampil, tanpa satu pun galat.
        $this->assertArrayHasKey('cross_border', $detail);
        $this->assertArrayNotHasKey('crossBorder', $detail);

        $this->assertSame('Singapura', $detail['cross_border']['destination_country']);
        $this->assertSame('AWS SG', $detail['cross_border']['destination_entity']);
        $this->assertArrayNotHasKey(
            'activity_name',
            $detail['cross_border'],
            'kalau kolom ini suatu saat benar-benar ada, label di TIA harus ikut diperbarui',
        );
    }

    #[Test]
    public function detail_tia_menyebut_pihak_ketiga_dengan_kolom_name(): void
    {
        $pihak = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data', 'country' => 'Singapura']);
        $dibuat = $this->postJson("/api/tia/from-vendor/{$pihak->id}")->assertSuccessful()->json('data');

        $detail = $this->getJson("/api/tia/{$dibuat['id']}")->assertOk()->json('data');

        // Kolomnya `name`. `vendor_name` tidak pernah dikirim relasi ini —
        // membacanya menghasilkan "undefined" di label dropdown dan ekspor.
        $this->assertSame('PT Awan Data', $detail['vendor']['name'] ?? null);
        $this->assertArrayNotHasKey('vendor_name', $detail['vendor']);
    }
}
