<?php

namespace Tests\Feature;

use App\Models\AccessibilityProvision;
use App\Models\CapacityAssessment;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentSubject;
use App\Models\Department;
use App\Models\DisabilityServiceScope;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\KelasSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint admin aksesibilitas — PP 33/2026 Pasal 39.
 *
 * Yang dijaga: "tersedia" tanpa tanggal uji tetap belum terbukti dan TIDAK
 * dijanjikan kepada widget; perubahan prasarana langsung terlihat widget
 * (cache config disegarkan); ragam mental tidak boleh mandiri; penilaian
 * kapasitas wajib beralasan; dua lapis penyaringan; izin baca/tulis.
 */
class AksesibilitasAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    // ───────────────────────── bantu ─────────────────────────

    private function pengguna(string $role, ?string $divisi, array $izin = ['consent_accessibility:read', 'consent_accessibility:write']): User
    {
        $departemen = $divisi ? Department::create(['org_id' => $this->org->id, 'name' => $divisi]) : null;

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => 'peran-'.uniqid(),
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    private function titik(string $nama, ?string $divisi = null): ConsentCollectionPoint
    {
        return ConsentCollectionPoint::create([
            'org_id' => $this->org->id,
            'collection_id' => 'CNT-'.substr(uniqid(), -6),
            'name' => $nama,
            'kind' => ConsentCollectionPoint::KIND_APP,
            'assign_group' => $divisi,
        ]);
    }

    // ───────────────────────── prasarana ─────────────────────────

    #[Test]
    public function prasarana_yang_tersedia_tanpa_tanggal_uji_belum_terbukti(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null));

        $r = $this->postJson('/api/consent-accessibility/accessibility/provisions', [
            'channel' => 'Widget Consent', 'format' => AccessibilityProvision::FORMAT_TTS, 'is_available' => true,
        ]);
        $r->assertStatus(201)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.sudah_terbukti', false)
            ->assertJsonPath('data.format_label', 'Pembacaan Suara (TTS)');

        $id = $r->json('data.id');

        // Baru setelah diuji ia terbukti.
        $this->putJson('/api/consent-accessibility/accessibility/provisions/'.$id, ['last_tested_at' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.sudah_terbukti', true);

        // Duplikat (kanal, format) ditolak — perbarui barisnya, jangan gandakan.
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget Consent', 'format' => AccessibilityProvision::FORMAT_TTS])
            ->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA');

        // Format di luar kosakata ditolak.
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget Consent', 'format' => 'telepati'])
            ->assertStatus(422)->assertJsonValidationErrors(['format']);

        $this->getJson('/api/consent-accessibility/accessibility/provisions')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson('/api/consent-accessibility/accessibility/provisions/'.$id)->assertOk();
        $this->getJson('/api/consent-accessibility/accessibility/provisions')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function config_publik_hanya_memaparkan_prasarana_yang_terbukti_dan_langsung_segar(): void
    {
        $cp = $this->titik('Formulir Pelajar');
        Sanctum::actingAs($this->pengguna('admin', null));

        // Isi cache config dulu — persis seperti widget yang sudah memuat.
        $this->getJson('/api/public/consent/config?collection_id='.$cp->collection_id)
            ->assertOk()->assertJsonCount(0, 'data.accessibility.formats');

        $terbukti = $this->postJson('/api/consent-accessibility/accessibility/provisions', [
            'channel' => 'Widget Pelajar', 'collection_point_id' => $cp->id,
            'format' => AccessibilityProvision::FORMAT_TTS, 'last_tested_at' => now()->toDateString(),
        ])->assertStatus(201)->json('data.id');

        $this->postJson('/api/consent-accessibility/accessibility/provisions', [
            'channel' => 'Widget Pelajar', 'collection_point_id' => $cp->id,
            'format' => AccessibilityProvision::FORMAT_BRAILLE, // tersedia, tapi tidak pernah diuji
        ])->assertStatus(201);

        $this->postJson('/api/consent-accessibility/accessibility/scopes', [
            'channel' => 'Widget Pelajar', 'collection_point_id' => $cp->id, 'ragam' => DisabilityServiceScope::RAGAM_NETRA,
        ])->assertStatus(201);

        // Hanya yang TERBUKTI yang dijanjikan — dan cache-nya sudah disegarkan.
        $this->getJson('/api/public/consent/config?collection_id='.$cp->collection_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.accessibility.formats')
            ->assertJsonPath('data.accessibility.formats.0.format', AccessibilityProvision::FORMAT_TTS)
            ->assertJsonPath('data.accessibility.ragam.0', DisabilityServiceScope::RAGAM_NETRA);

        // Dinonaktifkan → hilang dari config SEKETIKA, bukan setelah 5 menit.
        $this->putJson('/api/consent-accessibility/accessibility/provisions/'.$terbukti, ['is_available' => false])->assertOk();
        $this->getJson('/api/public/consent/config?collection_id='.$cp->collection_id)
            ->assertOk()->assertJsonCount(0, 'data.accessibility.formats');
    }

    // ───────────────────────── ragam ─────────────────────────

    #[Test]
    public function ragam_mengetahui_siapa_yang_boleh_menyetujui_sendiri(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null));

        $this->postJson('/api/consent-accessibility/accessibility/scopes', ['channel' => 'Loket Cabang', 'ragam' => DisabilityServiceScope::RAGAM_MENTAL])
            ->assertStatus(201)->assertJsonPath('data.boleh_mandiri', false);
        $this->postJson('/api/consent-accessibility/accessibility/scopes', ['channel' => 'Loket Cabang', 'ragam' => DisabilityServiceScope::RAGAM_NETRA])
            ->assertStatus(201)->assertJsonPath('data.boleh_mandiri', true);

        $this->postJson('/api/consent-accessibility/accessibility/scopes', ['channel' => 'Loket Cabang', 'ragam' => DisabilityServiceScope::RAGAM_NETRA])
            ->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA');
        $this->postJson('/api/consent-accessibility/accessibility/scopes', ['channel' => 'Loket Cabang', 'ragam' => 'telepati'])
            ->assertStatus(422)->assertJsonValidationErrors(['ragam']);

        $this->getJson('/api/consent-accessibility/accessibility/scopes')->assertOk()->assertJsonCount(2, 'data');
    }

    // ───────────────────────── penilaian kapasitas ─────────────────────────

    #[Test]
    public function penilaian_kapasitas_wajib_beralasan_dan_membuat_baris_subjek(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null));

        $this->postJson('/api/consent-accessibility/accessibility/assessments', [
            'subject' => 'x@contoh.id', 'result' => CapacityAssessment::HASIL_WALI,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertSame(0, ConsentSubject::count(), 'validasi gagal tidak boleh meninggalkan baris subjek');

        $this->postJson('/api/consent-accessibility/accessibility/assessments', [
            'subject' => 'x@contoh.id',
            'result' => CapacityAssessment::HASIL_WALI,
            'reason' => 'Subjek tidak dapat memahami penjelasan meski dibacakan ulang dua kali.',
        ])->assertStatus(201)
            ->assertJsonPath('data.subjek_memutuskan_sendiri', false)
            ->assertJsonPath('data.subject.label', 'x@contoh.id')
            ->assertJsonPath('data.subject_class', KelasSubjek::DISABILITAS);

        $this->assertSame(1, ConsentSubject::count());
        $this->assertSame(KelasSubjek::DISABILITAS, ConsentSubject::first()->subject_class);

        $this->getJson('/api/consent-accessibility/accessibility/assessments?subject='.urlencode('X@Contoh.ID'))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/consent-accessibility/accessibility/assessments?subject=lain@contoh.id')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ───────────────────────── penyaringan & izin ─────────────────────────

    #[Test]
    public function staf_divisi_melihat_prasarana_titik_divisinya_dan_kanal_umum_saja(): void
    {
        $hr = $this->titik('Formulir HR', 'HR');
        $keuangan = $this->titik('Formulir Keuangan', 'Finance');
        Sanctum::actingAs($this->pengguna('admin', null));

        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget HR', 'collection_point_id' => $hr->id, 'format' => AccessibilityProvision::FORMAT_TTS])->assertStatus(201);
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget Keuangan', 'collection_point_id' => $keuangan->id, 'format' => AccessibilityProvision::FORMAT_TTS])->assertStatus(201);
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Loket Cabang', 'format' => AccessibilityProvision::FORMAT_ISYARAT])->assertStatus(201);

        Sanctum::actingAs($this->pengguna('maker', 'HR'));
        $r = $this->getJson('/api/consent-accessibility/accessibility/provisions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['Loket Cabang', 'Widget HR'], array_column($r->json('data'), 'channel'));

        // Tidak bisa mendaftarkan prasarana atas titik yang tidak terlihat.
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget Keuangan', 'collection_point_id' => $keuangan->id, 'format' => AccessibilityProvision::FORMAT_BRAILLE])
            ->assertStatus(422)->assertJsonValidationErrors(['collection_point_id']);

        Sanctum::actingAs($this->pengguna('admin', null));
        $this->getJson('/api/consent-accessibility/accessibility/provisions')->assertOk()->assertJsonCount(3, 'data');
    }

    #[Test]
    public function tanpa_izin_consent_ditolak(): void
    {
        Sanctum::actingAs($this->pengguna('maker', null, ['ropa:read']));
        $this->getJson('/api/consent-accessibility/accessibility/summary')->assertStatus(403);

        Sanctum::actingAs($this->pengguna('maker', null, ['consent_accessibility:read']));
        $this->getJson('/api/consent-accessibility/accessibility/summary')->assertOk();
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'X', 'format' => AccessibilityProvision::FORMAT_TTS])->assertStatus(403);
        $this->postJson('/api/consent-accessibility/accessibility/assessments', ['subject' => 'x@contoh.id', 'result' => 'mampu', 'reason' => 'cukup panjang untuk lolos'])->assertStatus(403);
    }

    // ───────────────────────── ringkasan ─────────────────────────

    #[Test]
    public function ringkasan_membedakan_terbukti_dan_kedaluwarsa(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null));

        // Terbukti, tapi tinjau ulangnya sudah lewat → kedaluwarsa.
        $this->postJson('/api/consent-accessibility/accessibility/provisions', [
            'channel' => 'Widget', 'format' => AccessibilityProvision::FORMAT_TTS,
            'last_tested_at' => now()->subYears(2)->toDateString(), 'next_review_at' => now()->subMonth()->toDateString(),
        ])->assertStatus(201);
        // Tersedia, tidak pernah diuji → belum terbukti, dan BUKAN kedaluwarsa.
        $this->postJson('/api/consent-accessibility/accessibility/provisions', ['channel' => 'Widget', 'format' => AccessibilityProvision::FORMAT_BRAILLE])->assertStatus(201);
        $this->postJson('/api/consent-accessibility/accessibility/scopes', ['channel' => 'Widget', 'ragam' => DisabilityServiceScope::RAGAM_NETRA])->assertStatus(201);
        $this->postJson('/api/consent-accessibility/accessibility/assessments', ['subject' => 'a@contoh.id', 'result' => 'mampu', 'reason' => 'Memahami penjelasan dengan baik.'])->assertStatus(201);
        $this->postJson('/api/consent-accessibility/accessibility/assessments', ['subject' => 'b@contoh.id', 'result' => 'diwakili_wali', 'reason' => 'Tidak dapat memahami penjelasan.'])->assertStatus(201);

        $this->getJson('/api/consent-accessibility/accessibility/summary')
            ->assertOk()
            ->assertJsonPath('data.provisions', 2)
            ->assertJsonPath('data.provisions_proven', 1)
            ->assertJsonPath('data.provisions_stale', 1)
            ->assertJsonPath('data.channels_served', 1)
            ->assertJsonPath('data.assessments', 2)
            ->assertJsonPath('data.assessments_by_result.mampu', 1)
            ->assertJsonPath('data.assessments_by_result.diwakili_wali', 1)
            ->assertJsonPath('data.assessments_by_result.perlu_pendampingan', 0);
    }
}
