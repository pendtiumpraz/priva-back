<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Uji KARAKTERISASI untuk modul SELAIN RoPA/DPIA pada CRUD universal.
 *
 * Ditulis sebagai JARING PENGAMAN sebelum cabang ropa/dpia yang sudah tidak
 * terjangkau dihapus dari ModuleCrudController.
 *
 * Alasannya spesifik: kode mati itu berselang-seling dengan kode yang masih
 * hidup. `nextCode` tetap dipakai DSR/Consent/Breach, blok `switch ($module)`
 * memuat keempat modul berdampingan, dan di update() notifikasi status mencakup
 * dsr sementara dispatcher approval mencakup dsr + breach. Pisau yang meleset
 * di sana TIDAK akan tertangkap oleh 22 uji karakterisasi yang ada — semuanya
 * hanya menguji ropa/dpia, dan akan tetap hijau walau DSR/Consent/Breach rusak.
 *
 * Yang dikunci di sini sengaja diambil dari perilaku yang memang terbaca di
 * store(), bukan dari dugaan. Dua hal yang SENGAJA tidak diklaim:
 *   - `notification_required` TIDAK diturunkan dari severity di jalur ini (itu
 *     perilaku BreachApiController v1), jadi dikirim eksplisit;
 *   - isi preset Consent tidak ditebak — cukup normalisasi `kind`-nya.
 */
class ModuleCrudOtherModulesCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['dsr:read', 'dsr:write', 'consent:read', 'consent:write', 'breach:read', 'breach:write'],
        ]);
        Sanctum::actingAs(User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]));
    }

    // ---------- DSR ----------

    public function test_dsr_bernomor_otomatis_dan_bertenggat_72_jam(): void
    {
        $res = $this->postJson('/api/m/dsr', [
            'request_type' => 'access',
            'requester_name' => 'Budi Santoso',
            'requester_email' => 'budi@contoh.co.id',
            'description' => 'Permintaan akses data pribadi',
        ])->assertSuccessful();

        $dsr = DsrRequest::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertMatchesRegularExpression('/^DSR-'.date('Y').'-\d{3}$/', $dsr->request_id);
        $this->assertNotNull($dsr->deadline_at, 'DSR wajib bertenggat sejak dibuat');
        // 72 jam sejak dibuat — diberi kelonggaran semenit untuk waktu eksekusi.
        $this->assertEqualsWithDelta(72, $dsr->created_at->diffInHours($dsr->deadline_at, false), 1);
    }

    public function test_dsr_bisa_diperbarui(): void
    {
        $id = $this->postJson('/api/m/dsr', [
            'request_type' => 'erasure',
            'requester_name' => 'Ani',
            'requester_email' => 'ani@contoh.co.id',
        ])->assertSuccessful()->json('data.id');

        $this->putJson('/api/m/dsr/'.$id, ['status' => 'in_progress'])->assertOk();

        $this->assertSame('in_progress', DsrRequest::withoutGlobalScope('org')->find($id)->status);
    }

    // ---------- Consent ----------

    public function test_consent_bernomor_otomatis(): void
    {
        $res = $this->postJson('/api/m/consent', [
            'name' => 'Banner Situs Utama',
            'kind' => ConsentCollectionPoint::KIND_COOKIE,
        ])->assertSuccessful();

        $cp = ConsentCollectionPoint::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertMatchesRegularExpression('/^CNT-'.date('Y').'-\d{3}$/', $cp->collection_id);
        $this->assertSame(ConsentCollectionPoint::KIND_COOKIE, $cp->kind);
    }

    public function test_consent_jenis_tak_dikenal_jatuh_ke_cookie_banner(): void
    {
        $res = $this->postJson('/api/m/consent', [
            'name' => 'Titik Kumpul Aneh',
            'kind' => 'jenis-yang-tidak-ada',
        ])->assertSuccessful();

        $cp = ConsentCollectionPoint::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertSame(ConsentCollectionPoint::KIND_COOKIE, $cp->kind);
    }

    // ---------- Breach ----------

    public function test_breach_bernomor_otomatis_dan_menyiapkan_lini_masa(): void
    {
        $res = $this->postJson('/api/m/breach', [
            'title' => 'Kebocoran Basis Data Nasabah',
            'severity' => 'high',
            'description' => 'Akses tidak sah ke basis data',
            'source' => 'monitoring',
        ])->assertSuccessful();

        $breach = BreachIncident::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertMatchesRegularExpression('/^BRC-'.date('Y').'-\d{3}$/', $breach->incident_code);
        $this->assertNotNull($breach->detected_at, 'waktu terdeteksi diisi otomatis bila tidak dikirim');
        // Lini masa diinisialisasi saat insiden dicatat, bukan menunggu aksi.
        $this->assertNotEmpty($breach->timeline_log);
        $this->assertNotNull($breach->containment_checklist);
    }

    public function test_breach_yang_wajib_dinotifikasi_mendapat_tenggat_72_jam(): void
    {
        $res = $this->postJson('/api/m/breach', [
            'title' => 'Insiden Wajib Lapor',
            'severity' => 'critical',
            'notification_required' => true,
            'detected_at' => now()->subHours(2)->toDateTimeString(),
        ])->assertSuccessful();

        $breach = BreachIncident::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertNotNull($breach->notification_deadline);
        // PP 33/2026 Pasal 114(2): 3x24 jam dihitung sejak DIKETAHUI, bukan
        // sejak record dibuat — insiden yang baru dicatat belakangan tidak
        // boleh mendapat tenggat yang lebih longgar.
        $this->assertEqualsWithDelta(
            72,
            $breach->detected_at->diffInHours($breach->notification_deadline, false),
            1,
        );
    }

    public function test_perubahan_status_breach_menambah_lini_masa(): void
    {
        $id = $this->postJson('/api/m/breach', [
            'title' => 'Insiden Uji Transisi',
            'severity' => 'medium',
        ])->assertSuccessful()->json('data.id');

        $sebelum = count(BreachIncident::withoutGlobalScope('org')->find($id)->timeline_log ?? []);

        $this->putJson('/api/m/breach/'.$id, ['status' => 'containment'])->assertOk();

        $sesudah = BreachIncident::withoutGlobalScope('org')->find($id);
        $this->assertSame('containment', $sesudah->status);
        $this->assertGreaterThan(
            $sebelum,
            count($sesudah->timeline_log ?? []),
            'setiap transisi status insiden harus meninggalkan jejak di lini masa',
        );
    }
}
