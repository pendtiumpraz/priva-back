<?php

namespace Tests\Feature;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentSubject;
use App\Models\Department;
use App\Models\DsrRequest;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\KelasSubjek;
use App\Support\ModulSubjek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 10 — dua modul per SUBJEK: Children Pro (/consent-guardian, anak) dan
 * Inclusive Privacy (/consent-accessibility, disabilitas), masing-masing
 * lengkap: titik pengumpulan (CRUD), kewenangan wali, DSR.
 *
 * Yang dijaga: keduanya modul sidebar sendiri tepat setelah Consent dengan
 * izin sendiri (bukan `consent`); titik milik satu modul tidak muncul di
 * modul lain maupun di daftar Consent umum; kewenangan wali dan DSR disaring
 * per kelas subjek; nomor CNT/DSR lewat pembuat kode global; gerbang bukti
 * wali tetap berlaku dari pintu modul.
 */
class ModulSubjekTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'Bank Uji']);
    }

    // ───────────────────── sidebar & izin ─────────────────────

    #[Test]
    public function dua_modul_terdaftar_di_registri_menu_tepat_setelah_consent(): void
    {
        // DB uji hanya menjalankan migrasi (bukan MenuRegistrySeeder): baris
        // `consent` (sort 320, dari seeder) tidak ada di sini — yang diuji
        // adalah baris yang dipasang migrasi 000005, tepat di 321 dan 322.
        $wali = DB::table('menu_items')->where('menu_key', 'consent-guardian')->first();
        $aks = DB::table('menu_items')->where('menu_key', 'consent-accessibility')->first();

        $this->assertNotNull($wali);
        $this->assertNotNull($aks);
        $this->assertSame('/consent-guardian', $wali->href);
        $this->assertSame('/consent-accessibility', $aks->href);
        $this->assertSame('Subject Rights', $wali->section);
        $this->assertSame(321, (int) $wali->sort_order);
        $this->assertSame(322, (int) $aks->sort_order);
        $this->assertNull($wali->parent_menu_id, 'modul sendiri, bukan anak menu Consent');
        $this->assertSame('Baby', $wali->icon);
        $this->assertSame('Accessibility', $aks->icon);
        // Nama tampilan resmi (keputusan produk); nama teknis tidak ikut berubah.
        $this->assertSame('Children Pro', $wali->label);
        $this->assertSame('Inclusive Privacy', $aks->label);

        // Instalasi lama masih menyimpan nama kerja: migrasi 000009 hanya
        // mengganti labelnya, tanpa menyentuh id/menu_key/href.
        DB::table('menu_items')->where('menu_key', 'consent-guardian')->update(['label' => 'Consent Wali (Anak)']);
        DB::table('menu_items')->where('menu_key', 'consent-accessibility')->update(['label' => 'Consent Aksesibilitas (Disabilitas)']);
        $migrasi = include database_path('migrations/2026_09_16_000009_nama_modul_children_pro_inclusive_privacy.php');
        $migrasi->up();
        $this->assertSame('Children Pro', DB::table('menu_items')->where('menu_key', 'consent-guardian')->value('label'));
        $this->assertSame('Inclusive Privacy', DB::table('menu_items')->where('menu_key', 'consent-accessibility')->value('label'));
        $this->assertSame($wali->id, DB::table('menu_items')->where('menu_key', 'consent-guardian')->value('id'));
        $this->assertSame('/consent-guardian', DB::table('menu_items')->where('menu_key', 'consent-guardian')->value('href'));

        // Whitelist peran mengikuti consent: root, admin, dpo, maker.
        foreach ([$wali, $aks] as $menu) {
            $peran = DB::table('role_menu_whitelist')->where('menu_id', $menu->id)->pluck('role')->sort()->values()->all();
            $this->assertSame(['admin', 'dpo', 'maker', 'root'], $peran);
        }
    }

    #[Test]
    public function sidebar_pengguna_berizin_hanya_memuat_modul_yang_diizinkan(): void
    {
        // DPO dengan tenant role berbasis izin: hanya consent_guardian.
        Sanctum::actingAs($this->pengguna('dpo', null, ['consent:read', 'consent_guardian:read']));
        $isi = (string) $this->getJson('/api/menu-registry')->assertOk()->getContent();
        $this->assertStringContainsString('/consent-guardian', $isi);
        $this->assertStringNotContainsString('/consent-accessibility', $isi);

        // Admin melihat keduanya.
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));
        $isi = (string) $this->getJson('/api/menu-registry')->assertOk()->getContent();
        $this->assertStringContainsString('/consent-guardian', $isi);
        $this->assertStringContainsString('/consent-accessibility', $isi);

        // Peran berbasis izin yang tidak diberi modul mana pun: tidak melihat
        // keduanya — plafonnya whitelist admin, gerbangnya Role Settings.
        Sanctum::actingAs($this->pengguna('viewer', null, ['dsr:read']));
        $isi = (string) $this->getJson('/api/menu-registry')->assertOk()->getContent();
        $this->assertStringNotContainsString('/consent-guardian', $isi);
        $this->assertStringNotContainsString('/consent-accessibility', $isi);

        // Izin per modul benar-benar terpisah: aksesibilitas saja → hanya itu.
        Sanctum::actingAs($this->pengguna('viewer', null, ['consent_accessibility:read']));
        $isi = (string) $this->getJson('/api/menu-registry')->assertOk()->getContent();
        $this->assertStringNotContainsString('/consent-guardian', $isi);
        $this->assertStringContainsString('/consent-accessibility', $isi);
    }

    #[Test]
    public function backfill_menambah_izin_kedua_modul_ke_peran_yang_ada_tanpa_menimpa(): void
    {
        $editor = TenantRole::create(['org_id' => $this->org->id, 'name' => 'Editor', 'slug' => 'editor-'.Str::random(4), 'permissions' => ['consent:read', 'consent:write']]);
        $pembaca = TenantRole::create(['org_id' => $this->org->id, 'name' => 'Pembaca', 'slug' => 'pembaca-'.Str::random(4), 'permissions' => ['consent:read']]);
        $admin = TenantRole::create(['org_id' => $this->org->id, 'name' => 'Admin', 'slug' => 'admin-'.Str::random(4), 'permissions' => ['*']]);
        $sudah = TenantRole::create(['org_id' => $this->org->id, 'name' => 'Sudah', 'slug' => 'sudah-'.Str::random(4), 'permissions' => ['consent_guardian:read']]);

        $migrasi = include database_path('migrations/2026_09_16_000006_backfill_izin_consent_guardian_accessibility.php');
        $migrasi->up();

        $this->assertEqualsCanonicalizing(
            ['consent:read', 'consent:write', 'consent_guardian:read', 'consent_guardian:write', 'consent_accessibility:read', 'consent_accessibility:write'],
            $editor->fresh()->permissions,
        );
        $this->assertEqualsCanonicalizing(['consent:read', 'consent_guardian:read', 'consent_accessibility:read'], $pembaca->fresh()->permissions);
        $this->assertSame(['*'], $admin->fresh()->permissions);
        // Yang sudah punya consent_guardian tidak diubah pada modul itu; accessibility ditambah (read saja).
        $this->assertEqualsCanonicalizing(['consent_guardian:read', 'consent_accessibility:read'], $sudah->fresh()->permissions);
    }

    #[Test]
    public function izin_modul_terpisah_dari_consent_dan_satu_sama_lain(): void
    {
        // Izin consent saja: tidak masuk ke dua modul.
        Sanctum::actingAs($this->pengguna('maker', null, ['consent:read', 'consent:write']));
        $this->getJson('/api/consent-guardian/collection-points')->assertStatus(403);
        $this->getJson('/api/consent-accessibility/summary')->assertStatus(403);

        // Izin consent_guardian: masuk wali, tidak masuk aksesibilitas.
        Sanctum::actingAs($this->pengguna('maker', null, ['consent_guardian:read']));
        $this->getJson('/api/consent-guardian/collection-points')->assertOk();
        $this->getJson('/api/consent-guardian/summary')->assertOk()->assertJsonPath('data.subject_class', 'anak');
        $this->getJson('/api/consent-accessibility/collection-points')->assertStatus(403);
        $this->postJson('/api/consent-guardian/collection-points', ['name' => 'X'])->assertStatus(403);

        // Aksesibilitas punya endpoint prasarana; wali tidak.
        Sanctum::actingAs($this->pengguna('maker', null, ['consent_accessibility:read']));
        $this->getJson('/api/consent-accessibility/accessibility/summary')->assertOk();
        $this->getJson('/api/consent-accessibility/summary')->assertOk()->assertJsonPath('data.subject_class', 'disabilitas');
        $this->getJson('/api/consent-guardian/accessibility/summary')->assertStatus(404);

        // Rute lama tidak ada lagi.
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));
        $this->getJson('/api/guardian-consents')->assertStatus(404);
        $this->getJson('/api/accessibility/summary')->assertStatus(404);
        $this->getJson('/api/verification-methods')->assertStatus(404);
    }

    // ───────────────────── titik pengumpulan ─────────────────────

    #[Test]
    public function titik_dibuat_dari_modul_membawa_preset_modul_dan_nomor_cnt(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));

        $r = $this->postJson('/api/consent-guardian/collection-points', [
            'name' => 'Formulir Tabungan Pelajar',
            'domain' => 'bank-uji.co.id',
            'webhook_url' => 'https://bank-uji.co.id/hook',
            'guardian_label' => 'Data orang tua',
            'guardian_relation_options' => 'Ayah,Ibu,Wali Sah',
            'api_key_enabled' => true,
        ]);
        $r->assertStatus(201)
            ->assertJsonPath('data.owner_module', 'consent_guardian')
            ->assertJsonPath('data.subject_class_default', 'anak')
            ->assertJsonPath('data.kind', 'app_consent')
            ->assertJsonPath('data.guardian_label', 'Data orang tua')
            ->assertJsonPath('data.api_key_enabled', true)
            ->assertJsonPath('data.items_count', 0);
        $this->assertMatchesRegularExpression('/^CNT-\d{4}-\d{3,}$/', $r->json('data.collection_id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $r->json('data.embed_token'));
        // Kunci diterbitkan bersama titiknya: server key kembali SEKALI, di sini;
        // detail/daftar tidak pernah memuatnya lagi.
        $this->assertMatchesRegularExpression('/^pk_consent_/', $r->json('data.client_key'));
        $this->assertMatchesRegularExpression('/^sk_consent_/', $r->json('server_key'));
        $this->getJson('/api/consent-guardian/collection-points/'.$r->json('data.id'))
            ->assertOk()
            ->assertJsonMissingPath('data.server_key')
            ->assertJsonMissingPath('server_key');
        $this->assertStringStartsWith('pk_consent_', $r->json('data.client_key'));
        $this->assertArrayNotHasKey('server_key', $r->json('data'));

        $cp = ConsentCollectionPoint::find($r->json('data.id'));
        $this->assertTrue($cp->settings['guardian_mode']);
        $this->assertSame('anak', $cp->settings['subject_class_default']);

        // Widget membaca kelas bawaan dari config publik.
        $this->getJson('/api/public/consent/config?collection_id='.$cp->collection_id)
            ->assertOk()->assertJsonPath('data.collection.settings.subject_class_default', 'anak');

        // Titik ini milik modul wali: tampil di sana, tidak di aksesibilitas, tidak di Consent umum.
        $this->getJson('/api/consent-guardian/collection-points')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/consent-accessibility/collection-points')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/m/consent')->assertOk()->assertJsonCount(0, 'data');

        // Titik Consent umum tidak muncul di modul.
        ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-900', 'name' => 'Umum', 'kind' => 'app_consent']);
        $this->getJson('/api/consent-guardian/collection-points')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/m/consent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Umum');
    }

    #[Test]
    public function titik_modul_bisa_diubah_diberi_item_dan_dihapus_hanya_dari_modulnya(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));
        $id = $this->postJson('/api/consent-guardian/collection-points', ['name' => 'Formulir Anak'])->assertStatus(201)->json('data.id');
        $dasar = '/api/consent-guardian/collection-points/'.$id;

        // Ubah: settings di-merge, guardian_mode & kelas bawaan tidak bisa dimatikan.
        ConsentCollectionPoint::find($id)->update(['settings' => ['guardian_mode' => true, 'subject_class_default' => 'anak', 'logo_url' => 'https://x/logo.png']]);
        $this->putJson($dasar, ['name' => 'Formulir Anak v2', 'primary_color' => '#112233', 'guardian_label' => 'Wali'])
            ->assertOk()->assertJsonPath('data.name', 'Formulir Anak v2')->assertJsonPath('data.primary_color', '#112233');
        $s = ConsentCollectionPoint::find($id)->settings;
        $this->assertSame('https://x/logo.png', $s['logo_url']);
        $this->assertTrue($s['guardian_mode']);
        $this->assertSame('anak', $s['subject_class_default']);

        // Item: kepemilikan diperiksa, aturan kategori milik ConsentItemController tetap berlaku.
        $item = $this->postJson($dasar.'/items', ['title' => 'Penawaran tabungan pelajar', 'is_required' => false])->assertStatus(201)->json('data.id');
        $this->postJson($dasar.'/items', ['title' => 'X', 'category' => 'essential'])->assertStatus(422);
        $this->putJson($dasar.'/items/'.$item, ['title' => 'Penawaran (revisi)'])->assertOk();
        $this->getJson($dasar)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.title', 'Penawaran (revisi)');

        // Kunci API & embed & konfigurasi widget lewat modul.
        $this->postJson($dasar.'/regenerate-api-keys')->assertOk()->assertJsonStructure(['client_key', 'server_key']);
        $this->getJson($dasar.'/embed-snippet')->assertOk()->assertJsonStructure(['snippet']);
        $this->putJson($dasar.'/widget-config', ['locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');

        // Modul lain tidak bisa menyentuhnya walau id-nya tahu.
        $this->getJson('/api/consent-accessibility/collection-points/'.$id)->assertStatus(404);
        $this->putJson('/api/consent-accessibility/collection-points/'.$id, ['name' => 'Bajak'])->assertStatus(404);
        $this->deleteJson('/api/consent-accessibility/collection-points/'.$id.'/items/'.$item)->assertStatus(404);

        $this->deleteJson($dasar.'/items/'.$item)->assertOk();
        $this->deleteJson($dasar)->assertOk();
        $this->assertSoftDeleted('consent_collection_points', ['id' => $id]);
        $this->assertSame(0, ConsentItem::where('collection_point_id', $id)->count());
    }

    #[Test]
    public function titik_lama_bermode_wali_dipindahkan_ke_modul_wali_oleh_migrasi(): void
    {
        $wali = ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-001', 'name' => 'Lama Anak', 'kind' => 'app_consent', 'settings' => ['guardian_mode' => true]]);
        $umum = ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-002', 'name' => 'Lama Umum', 'kind' => 'app_consent', 'settings' => ['guardian_mode' => false]]);

        $migrasi = include database_path('migrations/2026_09_16_000007_pemilik_modul_titik_pengumpulan.php');
        $migrasi->up();

        $this->assertSame(ModulSubjek::GUARDIAN, $wali->fresh()->owner_module);
        $this->assertNull($umum->fresh()->owner_module);
    }

    #[Test]
    public function pembagian_divisi_berlaku_pada_titik_modul(): void
    {
        $hr = ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-010', 'name' => 'HR Anak', 'kind' => 'app_consent', 'owner_module' => ModulSubjek::GUARDIAN, 'assign_group' => 'HR']);
        ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-011', 'name' => 'Keuangan Anak', 'kind' => 'app_consent', 'owner_module' => ModulSubjek::GUARDIAN, 'assign_group' => 'Keuangan']);
        ConsentCollectionPoint::create(['org_id' => $this->org->id, 'collection_id' => 'CNT-2026-012', 'name' => 'Semua Anak', 'kind' => 'app_consent', 'owner_module' => ModulSubjek::GUARDIAN]);

        Sanctum::actingAs($this->pengguna('maker', 'HR', ['consent_guardian:read', 'consent_guardian:write']));
        $nama = collect($this->getJson('/api/consent-guardian/collection-points')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['HR Anak', 'Semua Anak'], $nama);
        $this->getJson('/api/consent-guardian/collection-points/'.$hr->id)->assertOk();

        Sanctum::actingAs($this->pengguna('admin', null, ['*']));
        $this->getJson('/api/consent-guardian/collection-points')->assertOk()->assertJsonCount(3, 'data');

        // Tenant lain tidak melihat apa pun.
        $lain = Organization::factory()->create();
        Sanctum::actingAs($this->pengguna('admin', null, ['*'], $lain));
        $this->getJson('/api/consent-guardian/collection-points')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/consent-guardian/collection-points/'.$hr->id)->assertStatus(404);
    }

    // ───────────────────── kewenangan wali per kelas ─────────────────────

    #[Test]
    public function kewenangan_wali_disaring_per_kelas_modul(): void
    {
        $this->kewenangan('anak@contoh.id', KelasSubjek::ANAK);
        $this->kewenangan('anak2@contoh.id', KelasSubjek::ANAK);
        $this->kewenangan('dis@contoh.id', KelasSubjek::DISABILITAS);

        Sanctum::actingAs($this->pengguna('admin', null, ['*']));

        $this->getJson('/api/consent-guardian/guardian-consents')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/consent-guardian/guardian-consents/stats')->assertOk()->assertJsonPath('data.terverifikasi', 2);
        $this->getJson('/api/consent-guardian/summary')->assertOk()->assertJsonPath('data.guardian_verified', 2);

        $this->getJson('/api/consent-accessibility/guardian-consents')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.label', 'dis@contoh.id');
        $this->getJson('/api/consent-accessibility/summary')->assertOk()->assertJsonPath('data.guardian_verified', 1);

        // Kewenangan disabilitas tidak bisa dibuka lewat modul wali, walau id-nya tahu.
        $dis = GuardianConsent::withoutGlobalScope('org')->whereHas('consentSubject', fn ($s) => $s->where('subject_class', 'disabilitas'))->sole();
        $this->getJson('/api/consent-guardian/guardian-consents/'.$dis->id)->assertStatus(404);
        $this->getJson('/api/consent-accessibility/guardian-consents/'.$dis->id)->assertOk();
    }

    // ───────────────────── DSR per kelas ─────────────────────

    #[Test]
    public function dsr_disaring_per_kelas_dan_bukti_wali_diputuskan_dari_modul(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));

        // Entri manual dari modul wali: kelas anak, pemohon bawaan wali, bukti menunggu.
        $r = $this->postJson('/api/consent-guardian/dsr', [
            'request_type' => 'deletion',
            'requester_name' => 'Siti Rahayu',
            'requester_email' => 'siti@contoh.id',
            'subject_identifier' => 'anak@contoh.id',
            'requester_relation' => 'orang tua',
            'description' => 'Minta data anak dihapus.',
        ]);
        $r->assertStatus(201)
            ->assertJsonPath('data.subject_class', 'anak')
            ->assertJsonPath('data.requester_type', 'wali')
            ->assertJsonPath('data.guardian_proof_status', 'menunggu')
            ->assertJsonPath('data.guardian_proof_required', true)
            ->assertJsonPath('data.destructive', true)
            ->assertJsonPath('data.verification_status', 'verified');
        $this->assertMatchesRegularExpression('/^DSR-\d{4}-\d{3,}$/', $r->json('data.request_id'));
        $id = (string) $r->json('data.id');

        // Wali tanpa penanda subjek ditolak.
        $this->postJson('/api/consent-guardian/dsr', ['request_type' => 'access', 'requester_name' => 'X', 'requester_email' => 'x@contoh.id', 'requester_type' => 'wali'])
            ->assertStatus(422)->assertJsonValidationErrors(['subject_identifier']);

        // DSR disabilitas & dewasa tidak muncul di modul wali.
        DsrRequest::create(['org_id' => $this->org->id, 'request_id' => 'DSR-2026-800', 'request_type' => 'access', 'requester_name' => 'D', 'requester_email' => 'd@contoh.id', 'status' => 'verified', 'subject_class' => 'disabilitas', 'requester_type' => 'subjek', 'deadline_at' => now()->addDay()]);
        DsrRequest::create(['org_id' => $this->org->id, 'request_id' => 'DSR-2026-801', 'request_type' => 'access', 'requester_name' => 'E', 'requester_email' => 'e@contoh.id', 'status' => 'verified', 'subject_class' => 'dewasa', 'requester_type' => 'subjek', 'deadline_at' => now()->addDay()]);

        $this->getJson('/api/consent-guardian/dsr')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/consent-accessibility/dsr')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.request_id', 'DSR-2026-800');
        $this->getJson('/api/consent-guardian/summary')->assertOk()->assertJsonPath('data.dsr_open', 1)->assertJsonPath('data.dsr_awaiting_proof', 1);
        $this->getJson('/api/consent-accessibility/dsr/'.$id)->assertStatus(404);

        // Gerbang bukti wali berlaku dari pintu modul: eksekusi hak merusak terkunci.
        $this->putJson('/api/consent-guardian/dsr/'.$id, ['status' => 'in_progress'])
            ->assertStatus(422)->assertJsonPath('code', 'BUKTI_WALI_BELUM_DITERIMA');

        // Keputusan bukti dari modul, lalu eksekusi terbuka.
        $this->postJson('/api/consent-guardian/dsr/'.$id.'/guardian-proof', ['decision' => 'diterima', 'reason' => 'Akta kelahiran diperiksa di cabang.'])
            ->assertOk();
        $this->putJson('/api/consent-guardian/dsr/'.$id, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->putJson('/api/consent-guardian/dsr/'.$id, ['status' => 'completed', 'response' => 'Data anak telah dihapus.'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $dsr = DsrRequest::find($id);
        $this->assertNotNull($dsr->responded_at);
        $this->assertNotNull($dsr->closed_at);

        // Izin: consent_guardian read saja tidak boleh memutuskan bukti.
        Sanctum::actingAs($this->pengguna('maker', null, ['consent_guardian:read']));
        $this->getJson('/api/consent-guardian/dsr')->assertOk();
        $this->postJson('/api/consent-guardian/dsr/'.$id.'/guardian-proof', ['decision' => 'ditolak', 'reason' => 'alasan yang cukup panjang'])->assertStatus(403);
    }

    // ───────────────────────── bantu ─────────────────────────

    /** @param  list<string>  $izin */
    // ───────────────────── cuplikan integrasi per modul ─────────────────────

    #[Test]
    public function cuplikan_embed_memakai_skrip_modulnya_sendiri_bukan_consent_form(): void
    {
        Sanctum::actingAs($this->pengguna('admin', null, ['*']));
        config(['app.frontend_url' => 'https://app.uji.id', 'app.frontend_url_explicit' => true]);

        $wali = $this->postJson('/api/consent-guardian/collection-points', ['name' => 'Portal Beasiswa'])->assertStatus(201)->json('data');
        $aks = $this->postJson('/api/consent-accessibility/collection-points', ['name' => 'Layanan Inklusif'])->assertStatus(201)->json('data');

        $c = $this->getJson('/api/consent-guardian/collection-points/'.$wali['id'].'/embed-snippet')->assertOk()->json();
        $this->assertSame('consent_guardian', $c['module']);
        $this->assertSame('https://app.uji.id/consent-guardian.js', $c['script_url']);
        $this->assertStringContainsString('consent-guardian.js', $c['snippet']);
        $this->assertStringContainsString('data-privasimu-consent-guardian', $c['snippet']);
        $this->assertStringContainsString('data-collection-id="'.$wali['embed_token'].'"', $c['snippet']);
        $this->assertStringContainsString('data-api-host="', $c['snippet']);
        $this->assertStringNotContainsString('consent-form.js', $c['snippet']);
        $this->assertStringNotContainsString('consent-banner.js', $c['snippet']);
        $this->assertSame('https://app.uji.id/embed/consent-guardian?collection_id='.$wali['embed_token'], $c['widget_url']);
        $this->assertStringStartsWith('https://app.uji.id/embed/subjek-preview?modul=guardian&collection_id=', $c['preview_url']);
        $this->assertNull($c['frontend_warning']);

        $d = $this->getJson('/api/consent-accessibility/collection-points/'.$aks['id'].'/embed-snippet')->assertOk()->json();
        $this->assertSame('consent_accessibility', $d['module']);
        $this->assertStringContainsString('consent-accessibility.js', $d['snippet']);
        $this->assertStringContainsString('data-privasimu-consent-accessibility', $d['snippet']);
        $this->assertStringNotContainsString('consent-guardian.js', $d['snippet']);
        $this->assertSame('https://app.uji.id/embed/consent-accessibility?collection_id='.$aks['embed_token'], $d['widget_url']);

        // Titik modul lain tidak bisa diminta cuplikannya lewat modul ini.
        $this->getJson('/api/consent-guardian/collection-points/'.$aks['id'].'/embed-snippet')->assertStatus(404);
    }

    private function pengguna(string $role, ?string $divisi, array $izin, ?Organization $org = null): User
    {
        $org ??= $this->org;
        $departemen = $divisi ? Department::create(['org_id' => $org->id, 'name' => $divisi]) : null;

        return User::factory()->create([
            'org_id' => $org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $org->id,
                'name' => 'peran-'.Str::random(6),
                'slug' => 'role-'.Str::random(6),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    private function kewenangan(string $penanda, string $kelas): GuardianConsent
    {
        $subjek = ConsentSubject::temukanAtauBuat($this->org->id, $penanda, ['subject_class' => $kelas]);
        $wali = Guardian::temukanAtauBuat($this->org->id, 'wali-'.Str::random(4).'@contoh.id', ['name' => 'Wali', 'relationship' => 'orang_tua']);

        return GuardianConsent::create([
            'org_id' => $this->org->id,
            'consent_subject_id' => $subjek->id,
            'guardian_id' => $wali->id,
            'verified_at' => now(),
            'verification_method_code' => 'otp_email',
            'verification_driver' => 'otp',
            'verification_confidence' => 'rendah',
        ]);
    }
}
