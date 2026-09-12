<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Dpia;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Uji KARAKTERISASI jalur tulis RoPA/DPIA (`/api/m/{module}`).
 *
 * Tujuannya bukan menilai apakah perilaku di bawah ini ideal, melainkan
 * MENGUNCI perilaku yang berlaku sekarang, supaya pengangkatan logika tulis ke
 * service bersama bisa dibuktikan tidak mengubah apa pun — bukan sekadar
 * diklaim begitu.
 *
 * Sebelum berkas ini ada, jalur tulis RoPA/DPIA praktis tak terjaga uji: hanya
 * sinkronisasi pivot pihak ketiga dan gerbang entitlement yang tercakup,
 * sementara penomoran, auto-risiko, DPIA otomatis, LIA otomatis, kunci
 * assign-group, dan kunci konten status `waiting` sama sekali tidak.
 *
 * Catatan penting yang ikut terkunci di sini: `applyRopaAutoRisk` berjalan
 * SEBELUM pemeriksaan DPIA otomatis dan MENIMPA `risk_level` kiriman pemanggil,
 * kecuali `risk_level_locked` bernilai benar. Uji yang mengirim `risk_level`
 * tanpa mengunci akan lulus atau gagal karena sebab yang salah.
 */
class ModuleCrudWriteCharacterizationTest extends TestCase
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

    /** @param array<string, mixed> $payload */
    private function buatRopa(array $payload = []): array
    {
        $res = $this->postJson('/api/m/ropa', array_merge([
            'processing_activity' => 'Pembukaan Rekening',
        ], $payload))->assertSuccessful();

        return $res->json();
    }

    /** RoPA berisiko tinggi yang PASTI tetap tinggi (kunci agar kalkulator tidak menimpa). */
    private function buatRopaHighRisk(array $payload = []): array
    {
        return $this->buatRopa(array_merge([
            'risk_level' => 'high',
            'risk_level_locked' => true,
        ], $payload));
    }

    // ---------- Penomoran ----------

    public function test_nomor_ropa_memakai_format_tahunan_saat_tanpa_kode_divisi(): void
    {
        $ropa = $this->buatRopa();

        $this->assertMatchesRegularExpression(
            '/^ROPA-'.date('Y').'-\d{3}$/',
            $ropa['data']['registration_number'],
        );
    }

    public function test_nomor_berurut_dan_tidak_bentrok_pada_pembuatan_beruntun(): void
    {
        $a = $this->buatRopa(['processing_activity' => 'Kegiatan A']);
        $b = $this->buatRopa(['processing_activity' => 'Kegiatan B']);

        $this->assertNotSame(
            $a['data']['registration_number'],
            $b['data']['registration_number'],
            'kendala unik nomor pendaftaran bersifat global — dua create beruntun tidak boleh bernomor sama',
        );
    }

    public function test_nomor_yang_dikirim_pemanggil_dihormati(): void
    {
        $ropa = $this->buatRopa(['registration_number' => 'ROPA-KHUSUS-001']);

        $this->assertSame('ROPA-KHUSUS-001', $ropa['data']['registration_number']);
    }

    public function test_nomor_memakai_kode_divisi_penanggung_jawab_bila_ada(): void
    {
        Department::create([
            'org_id' => $this->org->id,
            'name' => 'Retail',
            'code' => 'RTL',
        ]);

        $ropa = $this->buatRopa([
            'wizard_data' => ['detail_pemrosesan' => ['divisi_penanggung_jawab' => 'Retail']],
        ]);

        $this->assertMatchesRegularExpression(
            '/^ROPA-RTL-'.date('Y').'-\d{3}$/',
            $ropa['data']['registration_number'],
            'divisi penanggung jawab yang punya kode harus membentuk nomor per divisi',
        );
    }

    // ---------- Sinkronisasi pivot sistem informasi ----------

    public function test_sistem_terkait_di_wizard_tersinkron_ke_pivot(): void
    {
        $sistem = InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => 'Core Banking',
            'code' => 'CBS',
        ]);

        $ropa = $this->buatRopa([
            'wizard_data' => ['detail_pemrosesan' => ['sistem_terkait' => [$sistem->id]]],
        ]);

        $this->assertSame(
            [$sistem->id],
            DB::table('information_system_ropa')->where('ropa_id', $ropa['data']['id'])->pluck('information_system_id')->all(),
        );
    }

    // ---------- Auto-risiko ----------

    public function test_pembuatan_ropa_selalu_menuliskan_jejak_perhitungan_risiko(): void
    {
        $ropa = $this->buatRopa();

        $wizard = Ropa::find($ropa['data']['id'])->wizard_data ?? [];
        $this->assertArrayHasKey('risk_triggers', $wizard, 'hasil kalkulator risiko harus terekam di wizard_data');
        foreach (['level', 'triggers', 'reasons', 'computed_at'] as $kunci) {
            $this->assertArrayHasKey($kunci, $wizard['risk_triggers']);
        }
    }

    public function test_risiko_yang_dikunci_tidak_ditimpa_kalkulator(): void
    {
        $ropa = $this->buatRopa(['risk_level' => 'high', 'risk_level_locked' => true]);

        $this->assertSame('high', Ropa::find($ropa['data']['id'])->risk_level);
    }

    // ---------- DPIA otomatis ----------

    public function test_ropa_risiko_tinggi_memunculkan_dpia_draf(): void
    {
        $ropa = $this->buatRopaHighRisk(['assign_group' => 'Divisi Retail', 'assignees' => [$this->user->id]]);

        $this->assertNotNull($ropa['auto_dpia_id'], 'response membawa id DPIA otomatis');

        $dpia = Dpia::find($ropa['auto_dpia_id']);
        $this->assertSame($ropa['data']['id'], $dpia->ropa_id);
        $this->assertSame('draft', $dpia->status);
        $this->assertSame('high', $dpia->risk_level);
        $this->assertStringStartsWith('Auto-generated dari RoPA high-risk:', $dpia->description);
        // Penugasan DIWARISI dari RoPA induknya.
        $this->assertSame('Divisi Retail', $dpia->assign_group);
        $this->assertSame([$this->user->id], $dpia->assignees);
        // Koneksi balik ke RoPA induk ikut tertulis di wizard DPIA.
        $this->assertSame([$ropa['data']['id']], $dpia->wizard_data['koneksi_ropa']['connected_ropas']);
    }

    public function test_ropa_risiko_rendah_tidak_memunculkan_dpia(): void
    {
        $ropa = $this->buatRopa(['risk_level' => 'low', 'risk_level_locked' => true]);

        $this->assertNull($ropa['auto_dpia_id']);
        $this->assertSame(0, Dpia::count());
    }

    public function test_risiko_naik_ke_tinggi_saat_update_memunculkan_dpia(): void
    {
        $ropa = $this->buatRopa(['risk_level' => 'low', 'risk_level_locked' => true]);
        $this->assertSame(0, Dpia::count());

        $this->putJson('/api/m/ropa/'.$ropa['data']['id'], [
            'risk_level' => 'high',
            'risk_level_locked' => true,
        ])->assertOk();

        $this->assertSame(1, Dpia::count());
        $this->assertSame($ropa['data']['id'], Dpia::first()->ropa_id);
    }

    public function test_dpia_otomatis_tidak_digandakan(): void
    {
        $ropa = $this->buatRopaHighRisk();
        $this->assertSame(1, Dpia::count());

        // Update lanjutan pada RoPA yang tetap berisiko tinggi.
        $this->putJson('/api/m/ropa/'.$ropa['data']['id'], [
            'risk_level' => 'high',
            'risk_level_locked' => true,
            'processing_activity' => 'Pembukaan Rekening (revisi)',
        ])->assertOk();

        $this->assertSame(1, Dpia::count(), 'satu RoPA hanya boleh menumbuhkan satu DPIA otomatis');
    }

    // ---------- LIA otomatis ----------

    public function test_dasar_hukum_kepentingan_sah_memunculkan_lia(): void
    {
        $ropa = $this->buatRopa(['legal_basis' => 'kepentingan sah']);

        $this->assertNotNull($ropa['auto_lia_id'], 'RoPA berdasar kepentingan sah harus menumbuhkan LIA draf');
    }

    public function test_dasar_hukum_lain_tidak_memunculkan_lia(): void
    {
        $ropa = $this->buatRopa(['legal_basis' => 'kontrak']);

        $this->assertNull($ropa['auto_lia_id']);
    }

    // ---------- Sinkronisasi pivot DPIA ↔ RoPA ----------

    public function test_dpia_menautkan_ropa_dari_wizard(): void
    {
        $r1 = $this->buatRopa(['processing_activity' => 'Kegiatan Satu']);
        $r2 = $this->buatRopa(['processing_activity' => 'Kegiatan Dua']);

        $dpia = $this->postJson('/api/m/dpia', [
            'description' => 'DPIA Marketing Stack',
            'wizard_data' => ['koneksi_ropa' => ['connected_ropas' => [$r1['data']['id'], $r2['data']['id']]]],
        ])->assertSuccessful()->json();

        $tertaut = DB::table('dpia_ropa')->where('dpia_id', $dpia['data']['id'])->pluck('ropa_id')->all();
        $this->assertEqualsCanonicalizing([$r1['data']['id'], $r2['data']['id']], $tertaut);
    }

    // ---------- Kunci penyuntingan ----------

    public function test_assign_group_terkunci_saat_status_bukan_in_progress(): void
    {
        $ropa = $this->buatRopa(['status' => 'approved']);

        $this->putJson('/api/m/ropa/'.$ropa['data']['id'], ['assign_group' => 'Divisi Lain'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'approved');
    }

    public function test_konten_terkunci_saat_status_waiting_kecuali_transisi_murni(): void
    {
        $ropa = $this->buatRopa(['status' => 'waiting']);
        $id = $ropa['data']['id'];

        // Menyunting isi saat menunggu review → ditolak.
        $this->putJson('/api/m/ropa/'.$id, ['processing_activity' => 'Diubah diam-diam'])
            ->assertStatus(409);

        // Transisi status murni tetap diizinkan supaya alur re-open/approve jalan.
        $this->putJson('/api/m/ropa/'.$id, ['status' => 'in_progress'])->assertOk();
    }

    // ---------- Jejak audit ----------

    public function test_pembuatan_menulis_jejak_audit(): void
    {
        $ropa = $this->buatRopa();

        $this->assertTrue(
            AuditLog::where('module', 'ropa')
                ->where('record_id', $ropa['data']['id'])
                ->where('action', 'created')
                ->exists(),
        );
    }

    public function test_penyuntingan_bagian_wizard_tercatat_per_bagian(): void
    {
        $ropa = $this->buatRopa();

        $this->putJson('/api/m/ropa/'.$ropa['data']['id'], [
            'wizard_data' => ['detail_pemrosesan' => ['entitas' => 'PT Baru']],
        ])->assertOk();

        $this->assertTrue(
            AuditLog::where('module', 'ropa')
                ->where('record_id', $ropa['data']['id'])
                ->where('action', 'answer_added')
                ->where('section', 'detail_pemrosesan')
                ->exists(),
        );
    }

    // ---------- Batas tenant ----------

    public function test_org_id_kiriman_pemanggil_biasa_diabaikan(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);

        $ropa = $this->buatRopa(['org_id' => $lain->id]);

        $this->assertSame(
            $this->org->id,
            Ropa::withoutGlobalScope('org')->find($ropa['data']['id'])->org_id,
            'org_id dipaksa mengikuti pengguna — kiriman klien tidak boleh memindah tenant',
        );
    }
}
